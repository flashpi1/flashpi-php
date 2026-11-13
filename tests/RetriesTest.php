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
use Flashpi\Tests\Support\FakeClock;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Errors, retries and recoveries (Python: tests/test_retries.py).
 */
final class RetriesTest extends TestCase
{
    // Errors.

    /**
     * @return iterable<string, array{int, string, class-string<ApiException>}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, 'malformed_json', BadRequestException::class];
        yield '401' => [401, 'invalid_api_key', AuthenticationException::class];
        yield '403' => [403, 'key_read_only', PermissionDeniedException::class];
        yield '404' => [404, 'not_found', NotFoundException::class];
        yield '409' => [409, 'sequential_already_used', ConflictException::class];
        yield '413' => [413, 'payload_too_large', ApiException::class];
        yield '422' => [422, 'validation_failed', UnprocessableEntityException::class];
    }

    /**
     * @param class-string<ApiException> $class
     */
    #[DataProvider('statuses')]
    public function testMapsTheStatusToItsClassAndDoesNotRetryIt(int $status, string $code, string $class): void
    {
        $api = self::api([Replies::problem($status, $code)]);
        $error = self::throws($class, static fn() => $api->things->issue([]));
        self::assertSame($class, $error::class);
        self::assertSame($status, $error->getCode());
        self::assertCount(1, $api->sent);
    }

    public function testExposesTheProblemDetailsMembersAndExtensions(): void
    {
        $errors = [['field' => 'items[0].quantity', 'code' => 'out_of_range', 'message' => 'Too big']];
        $api = self::api([Replies::problem(422, 'total_mismatch', ['errors' => $errors, 'expectedTotal' => '10.00', 'computedTotal' => '11.50'])]);
        $error = self::throws(UnprocessableEntityException::class, static fn() => $api->things->issue([]));
        self::assertSame(422, $error->status);
        self::assertSame('total_mismatch', $error->errorCode);
        self::assertSame('Problem', $error->title);
        self::assertSame('Detail of total_mismatch', $error->detail);
        self::assertSame('req_problem', $error->requestId);
        self::assertSame($errors, $error->errors);
        self::assertSame('422 total_mismatch: Detail of total_mismatch', $error->getMessage());
        self::assertSame('10.00', $error->problem['expectedTotal'] ?? null);
        self::assertStringContainsString('problem+json', (string) $error->header('Content-Type'));
    }

    public function testHandlesAnErrorBodyThatIsNotProblemDetails(): void
    {
        $api = self::api([new Response(403, ['X-Request-Id' => 'edge_1'], '<html><body>403 Forbidden</body></html>')]);
        $error = self::throws(PermissionDeniedException::class, static fn() => $api->things->status());
        self::assertSame([null, null, [], 'edge_1'], [$error->errorCode, $error->detail, $error->errors, $error->requestId]);
        self::assertSame('403 <html><body>403 Forbidden</body></html>', $error->getMessage());
    }

    public function testDescribesAnEmptyErrorBody(): void
    {
        $api = self::api([new Response(404)]);
        self::throws(NotFoundException::class, static fn() => $api->things->status(), '/^404 \(no response body\)$/');
    }

    public function testTreatsARedirectAsAnErrorWithoutFollowingIt(): void
    {
        $api = self::api([new Response(301, ['Location' => 'https://example.com/'])]);
        $error = self::throws(ApiException::class, static fn() => $api->things->status());
        self::assertSame(301, $error->status);
        self::assertSame(ApiException::class, $error::class);
        self::assertCount(1, $api->sent);
        self::assertFalse($api->options[0]['allow_redirects'] ?? null);
    }

    // Retries.

    public function testRetriesA500WithTheSameKeyWaitingHalfASecondThenOne(): void
    {
        $api = self::api([
            Replies::problem(500, 'internal_error'),
            Replies::problem(502, 'internal_error'),
            Replies::json(Replies::document(), 202),
        ]);
        self::assertSame(Replies::ID, self::id($api->things->issue([])));
        self::assertCount(3, $api->sent);
        self::assertCount(1, array_unique($api->headers('idempotency-key')));
        self::assertSame(1.5, $api->clock->elapsed());
    }

    public function testThrowsTheLastErrorOnceTheRetriesRunOut(): void
    {
        $api = self::api(array_fill(0, 3, Replies::problem(503, 'service_unavailable')));
        self::throws(InternalServerException::class, static fn() => $api->things->status());
        self::assertCount(3, $api->sent);
    }

    public function testHonorsMaxRetriesOfTheClientAndOfTheRequest(): void
    {
        $first = self::api([Replies::problem(500, 'internal_error')], ['maxRetries' => 0]);
        self::throws(InternalServerException::class, static fn() => $first->things->status());
        self::assertCount(1, $first->sent);

        $second = self::api([Replies::problem(500, 'internal_error'), Replies::json([])], ['maxRetries' => 0]);
        self::assertSame([], $second->things->status(['maxRetries' => 1]));
        self::assertCount(2, $second->sent);
    }

    public function testRetriesNetworkErrorsAndWrapsTheLastOne(): void
    {
        $api = self::api(array_fill(0, 3, Replies::networkError('cURL error 7: Failed to connect')));
        $error = self::throws(ConnectionException::class, static fn() => $api->things->get(Replies::ID));
        self::assertNotInstanceOf(TimeoutException::class, $error);
        self::assertSame('GET https://api.flashpi.com.ec/v1/things/' . Replies::ID . ' failed: cURL error 7: Failed to connect', $error->getMessage());
        self::assertNull($error->getPrevious());
        self::assertCount(3, $api->sent);
    }

    public function testTimesOutASlowAttemptAndRetriesIt(): void
    {
        $clock = new FakeClock();
        $api = self::api([Replies::timeoutAfter($clock, 1), Replies::json(['ok' => true])], ['timeout' => 1.0], $clock);
        self::assertSame(['ok' => true], $api->things->status());
        self::assertCount(2, $api->sent);
        self::assertSame(1.5, $clock->elapsed());
    }

    public function testThrowsATimeoutErrorWhenEveryAttemptTimesOut(): void
    {
        $clock = new FakeClock();
        $api = self::api([Replies::timeoutAfter($clock, 2)], ['timeout' => 2.0, 'maxRetries' => 0], $clock);
        $error = self::throws(TimeoutException::class, static fn() => $api->things->status(), '/timed out after 2 seconds$/');
        self::assertInstanceOf(ConnectionException::class, $error);
    }

    public function testSendsTheTimeoutOfEachAttemptToGuzzle(): void
    {
        $api = self::api([Replies::json([]), Replies::json([])], ['timeout' => 7.5]);
        $api->things->status();
        $api->things->status(['timeout' => 2]);
        self::assertSame(7.5, $api->options[0]['timeout'] ?? null);
        self::assertSame(7.5, $api->options[0]['connect_timeout'] ?? null);
        self::assertSame(7.5, $api->options[0]['read_timeout'] ?? null);
        self::assertSame(2.0, $api->options[1]['timeout'] ?? null);
        self::assertFalse($api->options[1]['http_errors'] ?? null);
        self::assertFalse($api->options[1]['expect'] ?? null);
    }

    public function testWaitsAShortRetryAfterOfA429(): void
    {
        $api = self::api([Replies::problem(429, 'rate_limited', [], ['Retry-After' => '3']), Replies::json([])]);
        $api->things->status();
        self::assertCount(2, $api->sent);
        self::assertSame(3.0, $api->clock->elapsed());
    }

    public function testDoesNotWaitForADailyQuotaAndReportsWhenItResets(): void
    {
        $quota = Replies::problem(429, 'demo_issuer_quota_exceeded', ['limit' => 50, 'resetsAt' => '2026-11-13T05:00:00.000Z'], ['Retry-After' => '40000']);
        $api = self::api([$quota]);
        $error = self::throws(RateLimitException::class, static fn() => $api->things->issue([]));
        self::assertSame(40000, $error->retryAfter());
        self::assertSame(50, $error->problem['limit'] ?? null);
        self::assertCount(1, $api->sent);
    }

    public function testReadsARetryAfterGivenAsAnHttpDate(): void
    {
        $at = gmdate('D, d M Y H:i:s \G\M\T', time() + 2);
        $api = self::api([Replies::problem(503, 'x', [], ['Retry-After' => $at]), Replies::json([])]);
        $api->things->status();
        self::assertCount(2, $api->sent);
        self::assertGreaterThanOrEqual(1.0, $api->clock->sleeps[0] ?? 0.0);
        self::assertLessThanOrEqual(3.0, $api->clock->sleeps[0] ?? 0.0);
    }

    public function testRetriesA409InProgressAfterAtLeastASecond(): void
    {
        $api = self::api([
            Replies::problem(409, 'idempotency_key_in_progress'),
            Replies::json(Replies::document(), 202, ['Idempotent-Replayed' => 'true']),
        ]);
        self::assertSame(Replies::ID, self::id($api->things->issue([])));
        self::assertCount(2, $api->sent);
        self::assertSame(1.0, $api->clock->elapsed());
    }

    public function testDoesNotRetryTheMissingDemoCertificate(): void
    {
        $api = self::api([Replies::problem(503, 'demo_issuer_unavailable')]);
        self::throws(InternalServerException::class, static fn() => $api->things->issue([]));
        self::assertCount(1, $api->sent);
    }

    public function testDoesNotRetryA501(): void
    {
        $api = self::api([new Response(501)]);
        self::throws(InternalServerException::class, static fn() => $api->things->status());
        self::assertCount(1, $api->sent);
    }

    public function testRetriesA408(): void
    {
        $api = self::api([new Response(408), Replies::json([])]);
        self::assertSame([], $api->things->status());
    }

    public function testCapsTheBackoffAt8Seconds(): void
    {
        $api = self::api([...array_fill(0, 6, Replies::problem(500, 'internal_error')), Replies::json([])], ['maxRetries' => 6]);
        $api->things->status();
        self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0], $api->clock->sleeps);
        self::assertSame(23.5, $api->clock->elapsed());
    }

    public function testRetriesPatchAndDeleteToo(): void
    {
        $api = self::api([Replies::networkError(), Replies::json([]), Replies::networkError(), new Response(204)]);
        self::assertSame([], $api->things->update(Replies::ID, ['name' => 'x']));
        self::assertNull($api->things->delete(Replies::ID));
        self::assertSame(['PATCH', 'PATCH', 'DELETE', 'DELETE'], array_map(static fn($request): string => $request->getMethod(), $api->sent));
    }

    public function testRetriesAnyFailureOfAGuzzleTransfer(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('POST', '/');
        $api = self::api([
            new \GuzzleHttp\Exception\RequestException('Error creating resource: HTTP request failed!', $request),
            new \GuzzleHttp\Exception\RequestException('handler closed', $request),
            Replies::json([], 201),
        ]);
        self::assertSame([], $api->things->create([], ['idempotencyKey' => 'order-1']));
        self::assertCount(3, $api->sent);
        $last = self::api(array_fill(0, 3, new \GuzzleHttp\Exception\RequestException('Error creating resource', $request)));
        $error = self::throws(ConnectionException::class, static fn() => $last->things->create([], ['idempotencyKey' => 'order-1']));
        self::assertSame('POST https://api.flashpi.com.ec/v1/things failed: Error creating resource', $error->getMessage());
        self::assertSame('order-1', $error->idempotencyKey);
    }

    public function testRefusesARequestThatIsNotValidHttpWithoutQuotingIt(): void
    {
        $api = self::api();
        $spec = new \Flashpi\Internal\RequestSpec('POST', '/v1/things', body: [], accept: "application/json\r\nX-Injected: " . Replies::TEST_KEY);
        $error = self::throws(FlashpiException::class, static fn() => \Flashpi\Tests\Support\MockApi::engine($api->client)->request($spec));
        self::assertSame(FlashpiException::class, $error::class);
        self::assertSame('POST https://api.flashpi.com.ec/v1/things was not sent: it is not a valid HTTP request', $error->getMessage());
        self::assertNotNull($error->idempotencyKey);
        self::assertNull($error->getPrevious());
        self::assertSame([], $api->sent);
    }

    public function testThrowsOtherErrorsOfTheHttpClientAsTheyAre(): void
    {
        $api = self::api([new \LogicException('a bug in a middleware')]);
        self::throws(\LogicException::class, static fn() => $api->things->status(), '/^a bug in a middleware$/');
    }

    public function testTakesTheAnswerOfAClientThatThrowsForErrorStatuses(): void
    {
        $request = new \GuzzleHttp\Psr7\Request('GET', '/');
        $api = self::api([
            new \GuzzleHttp\Exception\ServerException('500', $request, Replies::problem(500, 'internal_error')),
            new \GuzzleHttp\Exception\ClientException('404', $request, Replies::problem(404, 'not_found')),
        ]);
        self::throws(NotFoundException::class, static fn() => $api->things->status());
        self::assertCount(2, $api->sent);
    }

    // Recovering a document created by an earlier attempt.

    public function testReturnsTheDocumentWhenARetryFindsItsKeyUsedByIt(): void
    {
        $api = self::api([
            Replies::problem(500, 'internal_error'),
            Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID]),
            Replies::json(Replies::document(Replies::OTHER_ID, 'authorized')),
        ]);
        $document = $api->things->issue([], ['language' => 'es', 'correlationId' => 'order-1']);
        self::assertIsArray($document);
        self::assertSame([Replies::OTHER_ID, 'authorized'], [$document['id'] ?? null, $document['status'] ?? null]);
        self::assertSame(
            ['POST /v1/invoices', 'POST /v1/invoices', 'GET /v1/documents/' . Replies::OTHER_ID],
            array_map(static fn($request): string => $request->getMethod() . ' ' . $request->getUri()->getPath(), $api->sent),
        );
        $read = $api->request(2);
        self::assertFalse($read->hasHeader('idempotency-key'));
        self::assertSame('es', $read->getHeaderLine('accept-language'));
        self::assertSame('order-1', $read->getHeaderLine('x-correlation-id'));
    }

    public function testReturnsTheReadsResponseWithWithResponse(): void
    {
        $api = self::api([
            Replies::networkError('reset'),
            Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID]),
            Replies::json(Replies::document(Replies::OTHER_ID), 200, ['X-Request-Id' => 'req_read']),
        ]);
        $result = $api->things->withResponse()->issue([]);
        self::assertSame(Replies::OTHER_ID, self::id($result->data));
        self::assertSame('req_read', $result->requestId);
        self::assertSame(200, $result->response->getStatusCode());
    }

    public function testThrowsTheReuseOnTheFirstAttempt(): void
    {
        $api = self::api([Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID])]);
        $error = self::throws(UnprocessableEntityException::class, static fn() => $api->things->issue([], ['idempotencyKey' => 'used-before']));
        self::assertSame('idempotency_key_reused', $error->errorCode);
        self::assertSame('used-before', $error->idempotencyKey);
        self::assertCount(1, $api->sent);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function reusesWithoutDocument(): iterable
    {
        yield 'no id' => [[]];
        yield 'empty id' => [['documentId' => '']];
        yield 'number' => [['documentId' => 7]];
    }

    /**
     * @param array<string, mixed> $extra
     */
    #[DataProvider('reusesWithoutDocument')]
    public function testThrowsAReuseWithoutADocumentId(array $extra): void
    {
        $api = self::api([Replies::networkError(), Replies::problem(422, 'idempotency_key_reused', $extra)]);
        self::throws(UnprocessableEntityException::class, static fn() => $api->things->issue([]));
        self::assertCount(2, $api->sent);
    }

    public function testDoesNotApplyToOtherPosts(): void
    {
        $api = self::api([
            Replies::problem(500, 'internal_error'),
            Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID]),
        ]);
        self::throws(UnprocessableEntityException::class, static fn() => $api->things->create([]));
        self::assertCount(2, $api->sent);
    }

    // A DELETE retried after a lost answer.

    public function testTreatsA404OnARetryAsDone(): void
    {
        $api = self::api([Replies::networkError('socket hang up'), Replies::problem(404, 'not_found')]);
        self::assertNull($api->things->delete(Replies::ID));
        self::assertCount(2, $api->sent);
    }

    public function testAnswersWithA204BuiltFromThe404(): void
    {
        $api = self::api([Replies::networkError('socket hang up'), Replies::problem(404, 'not_found', [], ['X-Other' => 'kept'])]);
        $result = $api->things->withResponse()->delete(Replies::ID);
        self::assertNull($result->data);
        self::assertSame(204, $result->response->getStatusCode());
        self::assertSame('', (string) $result->response->getBody());
        self::assertFalse($result->response->hasHeader('content-type'));
        self::assertSame('kept', $result->response->getHeaderLine('x-other'));
        self::assertSame('req_problem', $result->requestId);
    }

    public function testThrowsA404OnTheFirstAttempt(): void
    {
        $api = self::api([Replies::problem(404, 'not_found')]);
        self::throws(NotFoundException::class, static fn() => $api->things->delete(Replies::ID));
    }

    private static function id(mixed $document): mixed
    {
        self::assertIsArray($document);

        return $document['id'] ?? null;
    }
}
