<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Exception\ApiException;
use Flashpi\Exception\BadRequestException;
use Flashpi\Exception\ConflictException;
use Flashpi\Exception\ConnectionException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\TimeoutException;
use Flashpi\Exception\UnprocessableEntityException;
use Flashpi\Tests\Support\FakeClock;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Regression tests for the core findings of the Node and Python SDKs' reviews (Python:
 * tests/test_review_fixes.py). The webhook ones are in WebhooksTest.
 */
final class ReviewFixesTest extends TestCase
{
    // An earlier attempt still running.

    public function testWaitsForItWithTheSameKeyWithoutUsingUpTheRetries(): void
    {
        $api = self::api([
            Replies::networkError('socket hang up'),
            ...self::inProgress(5),
            Replies::json(Replies::document(Replies::ID, 'authorized'), 200, ['Idempotent-Replayed' => 'true']),
        ]);
        $document = $api->things->issue(['options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 30]]);
        self::assertSame([Replies::ID, 'authorized'], self::members($document, 'id', 'status'));
        self::assertCount(7, $api->sent);
        self::assertCount(1, array_unique($api->headers('idempotency-key')));
        // 0.5 s backoff after the network error, then 1 + 2 + 4 + 5 + 5 s of waiting.
        self::assertSame([0.5, 1.0, 2.0, 4.0, 5.0, 5.0], $api->clock->sleeps);
        self::assertSame(17.5, $api->clock->elapsed());
    }

    public function testStopsWhenTheCallsTimeIsUpAndHandsBackTheKey(): void
    {
        $api = self::api([Replies::networkError('socket hang up'), ...self::inProgress(20)], ['timeout' => 10.0]);
        $error = self::throws(ConflictException::class, static fn() => $api->things->issue([]));
        self::assertSame('idempotency_key_in_progress', $error->errorCode);
        self::assertSame($api->request(0)->getHeaderLine('idempotency-key'), $error->idempotencyKey);
        self::assertLessThanOrEqual(10.0, $api->clock->elapsed());
        self::assertGreaterThan(3, \count($api->sent));
    }

    public function testFollowsTheNormalRetryRulesOnTheFirstAttempt(): void
    {
        $api = self::api(self::inProgress(4));
        self::throws(ConflictException::class, static fn() => $api->things->issue([], ['idempotencyKey' => 'order-1', 'maxRetries' => 0]));
        self::assertCount(1, $api->sent);
    }

    public function testRecoversTheDocumentIfTheWaitEndsInIdempotencyKeyReused(): void
    {
        $api = self::api([
            Replies::networkError('socket hang up'),
            ...self::inProgress(1),
            Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID]),
            Replies::json(Replies::document(Replies::OTHER_ID, 'authorized')),
        ]);
        self::assertSame([Replies::OTHER_ID], self::members($api->things->issue([]), 'id'));
        self::assertSame('/v1/documents/' . Replies::OTHER_ID, $api->request(3)->getUri()->getPath());
    }

    public function testRetriesNormallyWhenTheAttemptTimedOutWithTheTimeSpent(): void
    {
        $clock = new FakeClock();
        $api = self::api([Replies::timeoutAfter($clock, 10), ...self::inProgress(1), Replies::json(Replies::document(), 202)], ['timeout' => 10.0], $clock);
        self::assertSame([Replies::ID], self::members($api->things->issue([]), 'id'));
        self::assertCount(3, $api->sent);
        self::assertSame([0.5, 1.0], $clock->sleeps);
    }

    public function testDoesNotWaitOnAnotherProcesssAttemptEvenAfterItsFirst409(): void
    {
        $api = self::api([...self::inProgress(3), Replies::json([], 202)]);
        self::throws(ConflictException::class, static fn() => $api->things->issue([], ['idempotencyKey' => 'order-1']));
        self::assertCount(3, $api->sent);
    }

    public function testWaitsAfterA5xxWithoutProblemDetails(): void
    {
        $api = self::api([
            new Response(502, [], '<html>502 Bad Gateway</html>'),
            ...self::inProgress(3),
            Replies::json(Replies::document(), 202),
        ]);
        self::assertSame([Replies::ID], self::members($api->things->issue([]), 'id'));
        self::assertCount(5, $api->sent);
        self::assertSame([0.5, 1.0, 2.0, 4.0], $api->clock->sleeps);
    }

    public function testDoesNotWaitAfterA5xxWithProblemDetails(): void
    {
        $api = self::api([Replies::problem(500, 'internal_error'), ...self::inProgress(2)]);
        self::throws(ConflictException::class, static fn() => $api->things->issue([]));
        self::assertCount(3, $api->sent);
    }

    // The Idempotency-Key on errors.

    public function testIsOnTheApiConnectionAndTimeoutErrorsOfAPost(): void
    {
        $api = self::api([Replies::problem(422, 'validation_failed')]);
        $error = self::throws(ApiException::class, static fn() => $api->things->issue([]));
        self::assertSame($api->request(0)->getHeaderLine('idempotency-key'), $error->idempotencyKey);

        $network = self::api([Replies::networkError()], ['maxRetries' => 0]);
        $connection = self::throws(ConnectionException::class, static fn() => $network->things->create([]));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $connection->idempotencyKey);

        $clock = new FakeClock();
        $slow = self::api([Replies::timeoutAfter($clock, 1)], ['maxRetries' => 0, 'timeout' => 1.0], $clock);
        $timeout = self::throws(TimeoutException::class, static fn() => $slow->things->issue([], ['idempotencyKey' => 'order-7']));
        self::assertSame('order-7', $timeout->idempotencyKey);
    }

    public function testIsOnABodyThatIsNotJson(): void
    {
        $api = self::api([new Response(201, [], '<html>')]);
        $error = self::throws(FlashpiException::class, static fn() => $api->things->create([], ['idempotencyKey' => 'order-8']), '/not JSON/');
        self::assertSame('order-8', $error->idempotencyKey);
    }

    public function testIsNotSetOnErrorsOfOtherMethods(): void
    {
        $api = self::api([Replies::problem(404, 'not_found')]);
        $error = self::throws(ApiException::class, static fn() => $api->things->get(Replies::ID));
        self::assertNull($error->idempotencyKey);
    }

    public function testComesFromAnIdempotencyKeyHeaderTooOnEveryAttempt(): void
    {
        $api = self::api([Replies::problem(503, 'x'), Replies::json([], 201)]);
        $api->things->create([], ['extraHeaders' => ['Idempotency-Key' => 'order-42']]);
        self::assertSame(['order-42', 'order-42'], $api->headers('idempotency-key'));
    }

    public function testPrefersTheOptionOverTheHeader(): void
    {
        $api = self::api([Replies::json([], 201)]);
        $api->things->create([], ['idempotencyKey' => 'option', 'extraHeaders' => ['idempotency-key' => 'header']]);
        self::assertSame(['option'], $api->request(0)->getHeader('idempotency-key'));
    }

    public function testStaysOnTheErrorWhenTheRecoveredDocumentCannotBeRead(): void
    {
        $api = self::api([
            Replies::networkError('reset'),
            Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID]),
            ...array_fill(0, 3, Replies::networkError('reset')),
        ]);
        $error = self::throws(ConnectionException::class, static fn() => $api->things->issue([]));
        self::assertSame($api->request(0)->getHeaderLine('idempotency-key'), $error->idempotencyKey);
        self::assertCount(5, $api->sent);
    }

    public function testStaysOnTheErrorWhenTheRecoveredIdIsNotAPathId(): void
    {
        $api = self::api([Replies::networkError('reset'), Replies::problem(422, 'idempotency_key_reused', ['documentId' => '..'])]);
        $error = self::throws(FlashpiException::class, static fn() => $api->things->issue([], ['idempotencyKey' => 'order-9']), '/Invalid id/');
        self::assertSame('order-9', $error->idempotencyKey);
        self::assertCount(2, $api->sent);
    }

    public function testSendsTheSameBodyOnARetryWhenTheCallerChangesTheArray(): void
    {
        $body = ['items' => [['code' => 'A']]];
        $changeThenFail = static function (RequestInterface $request) use (&$body): ResponseInterface {
            $body['items'][0] = ['code' => 'B'];

            throw new \GuzzleHttp\Exception\ConnectException('reset', $request);
        };
        $api = self::api([$changeThenFail, Replies::json(Replies::document(), 202)]);
        $api->things->issue($body);
        self::assertSame(['{"items":[{"code":"A"}]}', '{"items":[{"code":"A"}]}'], array_map(
            static fn(RequestInterface $request): string => (string) $request->getBody(),
            $api->sent,
        ));
    }

    // Ids that are dot segments.

    /**
     * @return iterable<string, array{string}>
     */
    public static function dotSegments(): iterable
    {
        yield 'dot' => ['.'];
        yield 'dots' => ['..'];
    }

    #[DataProvider('dotSegments')]
    public function testRefusesThemBeforeSendingAnything(string $value): void
    {
        $api = self::api();
        self::throws(FlashpiException::class, static fn() => $api->things->delete($value), "/^Invalid id in \\/v1\\/things\\/\\{id\\}: '\\.+'$/");
        self::throws(FlashpiException::class, static fn() => $api->things->getNested(Replies::ID, $value), '/Invalid id/');
        self::assertSame([], $api->sent);
    }

    public function testSendsIdsThatOnlyContainDotsEncoded(): void
    {
        $api = self::api([Replies::json([]), Replies::json([])]);
        $api->things->get('...');
        $api->things->get('%2e%2e');
        self::assertSame(['/v1/things/...', '/v1/things/%252e%252e'], array_map(
            static fn(RequestInterface $request): string => $request->getUri()->getPath(),
            $api->sent,
        ));
    }

    // Options.

    public function testLetsTheRequestLanguageWinOverAClientAcceptLanguageHeader(): void
    {
        $api = self::api([Replies::json([])], ['defaultHeaders' => ['Accept-Language' => 'es']]);
        $api->things->status(['language' => 'en']);
        self::assertSame(['en'], $api->request(0)->getHeader('accept-language'));
    }

    public function testLetsRequestHeadersWinOverTheRequestLanguage(): void
    {
        $api = self::api([Replies::json([])]);
        $api->things->status(['language' => 'en', 'extraHeaders' => ['accept-language' => 'es']]);
        self::assertSame(['es'], $api->request(0)->getHeader('accept-language'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badTimeouts(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'NaN' => [\NAN];
        yield 'infinite' => [\INF];
        yield 'past the limit' => [2 ** 31];
        yield 'true' => [true];
        yield 'text' => ['60'];
    }

    #[DataProvider('badTimeouts')]
    public function testRejectsARequestTimeoutBeforeSending(mixed $timeout): void
    {
        $api = self::api([Replies::json([])]);
        self::throws(FlashpiException::class, static fn() => $api->things->status(['timeout' => $timeout]), '/^timeout must be more than 0/');
        self::assertSame([], $api->sent);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badMaxRetries(): iterable
    {
        yield 'negative' => [-1];
        yield 'fraction' => [1.5];
        yield 'NaN' => [\NAN];
        yield 'false' => [false];
    }

    #[DataProvider('badMaxRetries')]
    public function testRejectsRequestMaxRetries(mixed $maxRetries): void
    {
        $api = self::api([Replies::json([])]);
        self::throws(FlashpiException::class, static fn() => $api->things->status(['maxRetries' => $maxRetries]), '/^maxRetries must be a whole number/');
        self::assertSame([], $api->sent);
    }

    public function testAcceptsExplicitNullOptionsAsTheDefaults(): void
    {
        $api = self::api([Replies::json([], 201)]);
        $options = ['timeout' => null, 'maxRetries' => null, 'idempotencyKey' => null, 'language' => null, 'correlationId' => null, 'extraHeaders' => null];
        self::assertSame([], $api->things->create([], $options));
        self::assertFalse($api->request(0)->hasHeader('x-correlation-id'));
    }

    public function testRaisesTheTimeoutOfASyncIssueToItsWaitPlus15Seconds(): void
    {
        $api = self::api(array_fill(0, 4, Replies::json([], 200)), ['timeout' => 5.0]);
        $api->things->issue(['options' => ['waitForAuthorization' => true]]);
        $api->things->issue(['options' => ['waitForAuthorization' => true]], ['timeout' => 60]);
        $api->things->issue(['options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 25]]);
        $api->things->issue(['options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 'soon']]);
        self::assertSame([30.0, 60.0, 40.0, 30.0], array_map(static fn(array $options): mixed => $options['timeout'] ?? null, $api->options));
    }

    public function testKeepsTheClientTimeoutWithoutWaitingForAuthorization(): void
    {
        $api = self::api([Replies::json([], 202), Replies::json([], 202)], ['timeout' => 5.0]);
        $api->things->issue(['options' => ['waitForAuthorization' => false, 'waitTimeoutSeconds' => 30]]);
        $api->things->issue(['options' => 'none']);
        self::assertSame([5.0, 5.0], array_map(static fn(array $options): mixed => $options['timeout'] ?? null, $api->options));
    }

    public function testPutsTheTrimmedIdempotencyKeyOnErrors(): void
    {
        $api = self::api([Replies::problem(400, 'validation_failed')]);
        $error = self::throws(BadRequestException::class, static fn() => $api->things->create([], ['idempotencyKey' => ' order-42 ']));
        self::assertSame('order-42', $error->idempotencyKey);
        self::assertSame('order-42', $api->request(0)->getHeaderLine('idempotency-key'));
    }

    public function testThrowsTheReuseOfADocumentIdThatIsNotText(): void
    {
        $api = self::api([Replies::networkError(), Replies::problem(422, 'idempotency_key_reused', ['documentId' => ['nested']])]);
        self::throws(UnprocessableEntityException::class, static fn() => $api->things->issue([]));
    }

    // The Node cases that name real resources, on the real resources (Python:
    // TestOnTheRealResources).

    public function testWaitsForAnEarlierInvoiceAttemptWithoutUsingUpTheRetries(): void
    {
        $api = self::api([
            Replies::networkError('socket hang up'),
            ...self::inProgress(5),
            Replies::json(Replies::document(Replies::ID, 'authorized'), 200, ['Idempotent-Replayed' => 'true']),
        ]);
        $document = $api->op('invoices.create', ['options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 30]]);
        self::assertSame([Replies::ID, 'authorized'], self::members($document, 'id', 'status'));
        self::assertCount(7, $api->sent);
        self::assertCount(1, array_unique($api->headers('idempotency-key')));
        self::assertSame(17.5, $api->clock->elapsed());
    }

    public function testRecoversTheInvoiceIfTheWaitEndsInIdempotencyKeyReused(): void
    {
        $api = self::api([
            Replies::networkError('socket hang up'),
            ...self::inProgress(1),
            Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID]),
            Replies::json(Replies::document(Replies::OTHER_ID, 'authorized')),
        ]);
        self::assertSame([Replies::OTHER_ID], self::members($api->op('invoices.create', []), 'id'));
        self::assertSame('/v1/documents/' . Replies::OTHER_ID, $api->request(3)->getUri()->getPath());
    }

    public function testPutsTheIdempotencyKeyOnTheErrorsOfAPost(): void
    {
        $api = self::api([Replies::problem(422, 'validation_failed')]);
        $error = self::throws(ApiException::class, static fn() => $api->op('invoices.create', []));
        self::assertSame($api->request(0)->getHeaderLine('idempotency-key'), $error->idempotencyKey);

        $network = self::api([Replies::networkError('x')], ['maxRetries' => 0]);
        $connection = self::throws(ConnectionException::class, static fn() => $network->op('issuers.create', []));
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $connection->idempotencyKey);

        $clock = new FakeClock();
        $slow = self::api([Replies::timeoutAfter($clock, 1)], ['maxRetries' => 0, 'timeout' => 1.0], $clock);
        $timeout = self::throws(TimeoutException::class, static fn() => $slow->op('invoices.create', [], ['idempotencyKey' => 'order-7']));
        self::assertSame('order-7', $timeout->idempotencyKey);

        $read = self::api([Replies::problem(404, 'not_found')]);
        $notFound = self::throws(ApiException::class, static fn() => $read->op('documents.get', Replies::ID));
        self::assertNull($notFound->idempotencyKey);
    }

    public function testTakesTheIdempotencyKeyFromAHeaderOnEveryAttempt(): void
    {
        $api = self::api([Replies::problem(503, 'x'), Replies::json([], 201)]);
        $api->op('issuers.create', [], ['extraHeaders' => ['Idempotency-Key' => 'order-42']]);
        self::assertSame(['order-42', 'order-42'], $api->headers('idempotency-key'));
    }

    public function testKeepsTheKeyWhenTheRecoveredDocumentCannotBeRead(): void
    {
        $api = self::api([
            Replies::networkError('reset'),
            Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID]),
            ...array_fill(0, 3, Replies::networkError('reset')),
        ]);
        $error = self::throws(ConnectionException::class, static fn() => $api->op('withholdings.create', []));
        self::assertSame($api->request(0)->getHeaderLine('idempotency-key'), $error->idempotencyKey);
    }

    public function testSendsTheSameInvoiceOnARetryWhenTheCallerChangesIt(): void
    {
        $body = ['items' => [['code' => 'A']]];
        $changeThenFail = static function (RequestInterface $request) use (&$body): ResponseInterface {
            $body['items'][0] = ['code' => 'B'];

            throw new \GuzzleHttp\Exception\ConnectException('reset', $request);
        };
        $api = self::api([$changeThenFail, Replies::json(Replies::document(), 202)]);
        $api->op('invoices.create', $body);
        self::assertSame(['{"items":[{"code":"A"}]}', '{"items":[{"code":"A"}]}'], array_map(
            static fn(RequestInterface $request): string => (string) $request->getBody(),
            $api->sent,
        ));
    }

    #[DataProvider('dotSegments')]
    public function testRefusesDotSegmentIdsOfTheRealResourcesBeforeSendingAnything(string $value): void
    {
        $api = self::api();
        $calls = [
            ['issuers.establishments.delete', [Replies::ID, $value]],
            ['issuers.establishments.emissionPoints.delete', [Replies::ID, Replies::ID, $value]],
            ['issuers.certificates.get', [Replies::ID, $value]],
            ['webhookEndpoints.delete', [$value]],
            ['webhookEndpoints.deliveries.list', [$value]],
            ['documents.xml', [$value]],
        ];
        foreach ($calls as [$name, $arguments]) {
            self::throws(FlashpiException::class, static fn() => $api->op($name, ...$arguments), '/Invalid id/');
        }
        self::assertSame([], $api->sent);
    }

    public function testRefusesEmptyIdsOfTheRealResourcesBeforeSendingAnything(): void
    {
        $api = self::api();
        self::throws(FlashpiException::class, static fn() => $api->op('issuers.get', ''), "/^Missing id in \\/v1\\/issuers\\/\\{id\\}: got ''$/");
        self::throws(FlashpiException::class, static fn() => $api->op('issuers.establishments.emissionPoints.list', Replies::ID, ' '), '/\\/establishments\\/\\{id\\}\\/emission-points/');
        foreach (["\f", "\u{A0}", "\u{FEFF}"] as $value) {
            self::throws(FlashpiException::class, static fn() => $api->op('documents.get', $value), '/^Missing id in \\/v1\\/documents\\/\\{id\\}: got /');
        }
        self::assertSame([], $api->sent);
    }

    public function testSendsIdsOfTheRealResourcesThatOnlyContainDotsEncoded(): void
    {
        $api = self::api([Replies::json([]), Replies::json([])]);
        $api->op('documents.get', '...');
        $api->op('documents.get', '%2e%2e');
        self::assertSame(['/v1/documents/...', '/v1/documents/%252e%252e'], array_map(
            static fn(RequestInterface $request): string => $request->getUri()->getPath(),
            $api->sent,
        ));
    }

    public function testLetsTheRequestLanguageOfARealResourceWinOverAClientAcceptLanguageHeader(): void
    {
        $api = self::api([Replies::json([])], ['defaultHeaders' => ['Accept-Language' => 'es']]);
        $api->op('status.get', ['language' => 'en']);
        self::assertSame(['en'], $api->request(0)->getHeader('accept-language'));
    }

    #[DataProvider('badTimeouts')]
    public function testRejectsARequestTimeoutOfARealResourceBeforeSending(mixed $timeout): void
    {
        $api = self::api([Replies::json([])]);
        self::throws(FlashpiException::class, static fn() => $api->op('status.get', ['timeout' => $timeout]), '/timeout/');
        self::assertSame([], $api->sent);
    }

    public function testAcceptsExplicitNullOptionsOfARealResourceAsTheDefaults(): void
    {
        $api = self::api([Replies::json([])]);
        self::assertSame([], $api->op('status.get', ['timeout' => null, 'maxRetries' => null, 'idempotencyKey' => null]));
    }

    /**
     * @return list<ResponseInterface>
     */
    private static function inProgress(int $count): array
    {
        return array_map(static fn(): ResponseInterface => Replies::problem(409, 'idempotency_key_in_progress'), range(1, $count));
    }

    /**
     * @return list<mixed>
     */
    private static function members(mixed $document, string ...$names): array
    {
        self::assertIsArray($document);

        return array_values(array_map(static fn(string $name): mixed => $document[$name] ?? null, $names));
    }
}
