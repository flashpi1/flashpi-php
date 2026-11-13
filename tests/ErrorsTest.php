<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Exception\ApiException;
use Flashpi\Exception\AuthenticationException;
use Flashpi\Exception\BadRequestException;
use Flashpi\Exception\ConflictException;
use Flashpi\Exception\ConnectionException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\InternalServerException;
use Flashpi\Exception\NotFoundException;
use Flashpi\Exception\PermissionDeniedException;
use Flashpi\Exception\RateLimitException;
use Flashpi\Exception\TimeoutException;
use Flashpi\Exception\UnprocessableEntityException;
use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Internal\RetryAfter;
use Flashpi\Internal\Text;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The exception classes, apart from the requests that throw them (Python: tests/test_errors.py).
 */
final class ErrorsTest extends TestCase
{
    private const PROBLEM = [
        'type' => 'https://flashpi.com.ec/docs/en/errors/total_mismatch',
        'title' => 'Problem',
        'status' => 422,
        'detail' => 'Detail of total_mismatch',
        'instance' => '/v1/invoices',
        'code' => 'total_mismatch',
        'errors' => [['field' => 'items[0].quantity', 'code' => 'out_of_range', 'message' => 'Too big']],
        'requestId' => 'req_problem',
        'expectedTotal' => '10.00',
    ];

    /**
     * @return iterable<string, array{int, class-string<ApiException>}>
     */
    public static function classes(): iterable
    {
        yield '400' => [400, BadRequestException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '403' => [403, PermissionDeniedException::class];
        yield '404' => [404, NotFoundException::class];
        yield '409' => [409, ConflictException::class];
        yield '413' => [413, ApiException::class];
        yield '422' => [422, UnprocessableEntityException::class];
        yield '429' => [429, RateLimitException::class];
        yield '500' => [500, InternalServerException::class];
        yield '503' => [503, InternalServerException::class];
        yield '301' => [301, ApiException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('classes')]
    public function testEachStatusGetsItsClass(int $status, string $class): void
    {
        $error = ApiException::fromResponse($status, [], '');
        self::assertSame($class, $error::class);
        self::assertInstanceOf(FlashpiException::class, $error);
        self::assertSame($status, $error->status);
        self::assertSame($status, $error->getCode());
    }

    public function testReadsTheProblemDetailsAndTheirExtensions(): void
    {
        $error = ApiException::fromResponse(422, ['x-request-id' => ['req_header']], (string) json_encode(self::PROBLEM));
        self::assertSame('total_mismatch', $error->errorCode);
        self::assertSame('Problem', $error->title);
        self::assertSame('Detail of total_mismatch', $error->detail);
        self::assertSame(self::PROBLEM['errors'], $error->errors);
        self::assertSame('req_problem', $error->requestId);
        self::assertSame('10.00', $error->problem['expectedTotal'] ?? null);
        self::assertSame('422 total_mismatch: Detail of total_mismatch', $error->getMessage());
        self::assertNull($error->idempotencyKey);
        self::assertSame('req_header', $error->header('X-Request-Id'));
        self::assertNull($error->header('retry-after'));
    }

    public function testFallsBackToTheTitleAndTheHeaderRequestId(): void
    {
        $body = (string) json_encode(['code' => 'rate_limited', 'title' => 'Too many requests', 'errors' => 'not a list']);
        $error = ApiException::fromResponse(429, ['x-request-id' => ['req_1']], $body);
        self::assertSame('429 rate_limited: Too many requests', $error->getMessage());
        self::assertNull($error->detail);
        self::assertSame([], $error->errors);
        self::assertSame('req_1', $error->requestId);
        self::assertSame('400 x', ApiException::fromResponse(400, [], '{"code": "x"}')->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodies(): iterable
    {
        yield 'html' => ['<html><body>403 Forbidden</body></html>', '403 <html><body>403 Forbidden</body></html>'];
        yield 'empty' => ['', '403 (no response body)'];
        yield 'spaces' => ["  \n ", '403 (no response body)'];
        yield 'no code' => ['{"message": "no code"}', '403 {"message": "no code"}'];
        yield 'code that is not text' => ['{"code": 7}', '403 {"code": 7}'];
        yield 'list' => ['[1, 2]', '403 [1, 2]'];
        yield 'long' => ["a\n\n  b" . str_repeat('c', 300), '403 a b' . str_repeat('c', 197)];
        yield 'not UTF-8' => ["bad \xff byte", "403 bad \u{FFFD} byte"];
        yield 'unicode spaces' => ["Ñandú\u{3000}\u{A0}€", '403 Ñandú €'];
    }

    #[DataProvider('bodies')]
    public function testDescribesBodiesThatAreNotProblemDetails(string $body, string $message): void
    {
        $error = ApiException::fromResponse(403, [], $body);
        self::assertNull($error->errorCode);
        self::assertNull($error->problem);
        self::assertSame($message, $error->getMessage());
    }

    public function testReadsRetryAfterInSecondsOrAsADate(): void
    {
        $now = (float) gmmktime(12, 0, 0, 11, 13, 2026);
        self::assertNull(RetryAfter::parse(null));
        self::assertSame(3, RetryAfter::parse('3'));
        self::assertSame(40000, RetryAfter::parse(' 40000 '));
        self::assertSame(2, RetryAfter::parse('Fri, 13 Nov 2026 12:00:02 GMT', $now));
        self::assertSame(0, RetryAfter::parse('Fri, 13 Nov 2026 11:59:00 GMT', $now));
        self::assertSame(2, RetryAfter::parse('Friday, 13-Nov-26 12:00:02 GMT', $now));
        self::assertSame(2, RetryAfter::parse('Fri Nov 13 12:00:02 2026', $now));
        self::assertSame(2, RetryAfter::parse('Fri, 13 Nov 2026 07:00:02 -0500', $now));
        self::assertSame(2, RetryAfter::parse('2026-11-13T12:00:01.500Z', $now));
        self::assertSame(5, RetryAfter::parse('2026-11-13T12:00:05', $now));
        self::assertSame(5, RetryAfter::parse('2026-11-13T07:00:05-05:00', $now));
        self::assertNull(RetryAfter::parse('soon'));
        self::assertNull(RetryAfter::parse('-1'));
        self::assertNull(RetryAfter::parse('1.5'));
        self::assertNull(RetryAfter::parse('٣'));
        self::assertNull(RetryAfter::parse('Fri, 31 Nov 2026 12:00:02 GMT'));
        self::assertNull(RetryAfter::parse('2026-13-45T99:00:00Z'));
        self::assertSame(10 ** 18, RetryAfter::parse(str_repeat('9', 5000)));
    }

    public function testReadsRetryAfterAgainstTheClock(): void
    {
        $seconds = RetryAfter::parse(gmdate('D, d M Y H:i:s \G\M\T'));
        self::assertNotNull($seconds);
        self::assertGreaterThanOrEqual(0, $seconds);
        self::assertLessThanOrEqual(1, $seconds);
        self::assertSame(7, ApiException::fromResponse(429, ['retry-after' => ['7']], '')->retryAfter());
        self::assertNull(ApiException::fromResponse(429, [], '')->retryAfter());
    }

    public function testTheOtherExceptionsCarryTheirMessageAndKey(): void
    {
        $timeout = new TimeoutException('GET / timed out');
        self::assertInstanceOf(ConnectionException::class, $timeout);
        self::assertNull($timeout->idempotencyKey);
        $previous = new \RuntimeException('cause');
        self::assertSame($previous, (new FlashpiException('x', $previous))->getPrevious());
        $webhook = new WebhookVerificationException(WebhookVerificationException::INVALID_SECRET, 'Bad');
        self::assertSame('invalid_secret', $webhook->reason);
        self::assertSame('Bad', $webhook->getMessage());
    }

    public function testScrubsTextThatIsNotUtf8(): void
    {
        self::assertSame('Ñandú', Text::scrub('Ñandú'));
        self::assertSame("a\u{FFFD}b", Text::scrub("a\xffb"));
        self::assertSame("\u{FFFD}\u{FFFD}\u{FFFD}", Text::scrub("\xED\xA0\x80"));
        self::assertSame('a b', Text::excerpt("a\u{2028}b", 3));
        self::assertSame('ñ', Text::excerpt('ñandú', 1));
    }
}
