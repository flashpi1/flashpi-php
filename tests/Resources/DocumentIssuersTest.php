<?php

declare(strict_types=1);

namespace Flashpi\Tests\Resources;

use Flashpi\ApiResponse;
use Flashpi\Exception\TimeoutException;
use Flashpi\Tests\Support\FakeClock;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The six document types: `$flashpi->invoices`, `creditNotes`, `debitNotes`,
 * `purchaseSettlements`, `waybills` and `withholdings` (Python: tests/test_resources.py's
 * TestIssuingDocuments).
 */
final class DocumentIssuersTest extends TestCase
{
    private const DOCUMENT = 'doc_1';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documentTypes(): iterable
    {
        yield 'invoices' => ['invoices', '/v1/invoices'];
        yield 'credit notes' => ['creditNotes', '/v1/credit-notes'];
        yield 'debit notes' => ['debitNotes', '/v1/debit-notes'];
        yield 'purchase settlements' => ['purchaseSettlements', '/v1/purchase-settlements'];
        yield 'waybills' => ['waybills', '/v1/waybills'];
        yield 'withholdings' => ['withholdings', '/v1/withholdings'];
    }

    #[DataProvider('documentTypes')]
    public function testSendsItsOperation(string $resource, string $path): void
    {
        $api = self::api([Replies::json(Replies::document(), 202)]);
        $api->op("{$resource}.create", ['a' => 1]);
        self::assertSentOnly($api, 'POST', $path, json: '{"a":1}');
        self::assertSame('application/json', $api->request(0)->getHeaderLine('accept'));
    }

    public function testReturnsTheDocumentOfA200AndOfA202(): void
    {
        $api = self::api([
            Replies::json(Replies::document(self::DOCUMENT, 'authorized')),
            Replies::json(Replies::document(self::DOCUMENT), 202),
        ]);
        self::assertSame('authorized', self::member($api->op('invoices.create', []), 'status'));
        self::assertSame('processing', self::member($api->op('invoices.create', []), 'status'));
    }

    #[DataProvider('documentTypes')]
    public function testWaitsForTheSriLongerThanTheClientTimeoutInSyncMode(string $resource, string $path): void
    {
        $api = self::api([Replies::json(Replies::document(self::DOCUMENT, 'authorized'))], ['timeout' => 5.0, 'maxRetries' => 0]);
        $body = ['options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 10]];
        self::assertSame('authorized', self::member($api->op("{$resource}.create", $body), 'status'));
        self::assertSame($path, $api->request(0)->getUri()->getPath());
        self::assertSame([25.0], $api->timeouts());
    }

    /**
     * @return iterable<string, array{mixed, float}>
     */
    public static function syncSettings(): iterable
    {
        yield 'waiting' => [['waitForAuthorization' => true], 30.0];
        yield 'waiting 1.5 s' => [['waitForAuthorization' => true, 'waitTimeoutSeconds' => 1.5], 16.5];
        yield 'waiting null seconds' => [['waitForAuthorization' => true, 'waitTimeoutSeconds' => null], 30.0];
        yield 'waiting "20" seconds' => [['waitForAuthorization' => true, 'waitTimeoutSeconds' => '20'], 30.0];
        yield 'waiting true seconds' => [['waitForAuthorization' => true, 'waitTimeoutSeconds' => true], 30.0];
        yield 'not waiting' => [['waitForAuthorization' => false, 'waitTimeoutSeconds' => 30], 5.0];
        yield 'waiting "yes"' => [['waitForAuthorization' => 'yes'], 5.0];
        yield 'no settings' => [[], 5.0];
        yield 'null options' => [null, 5.0];
        yield 'options that are text' => ['sync', 5.0];
    }

    #[DataProvider('syncSettings')]
    public function testRaisesTheTimeoutOnlyForASyncWait(mixed $settings, float $timeout): void
    {
        $api = self::api([Replies::json(Replies::document(), 202)], ['timeout' => 5.0]);
        $api->op('invoices.create', ['options' => $settings]);
        self::assertSame([$timeout], $api->timeouts());
    }

    public function testKeepsALongerClientOrRequestTimeout(): void
    {
        $api = self::api([Replies::json([]), Replies::json([])], ['timeout' => 90.0]);
        $body = ['options' => ['waitForAuthorization' => true]];
        $api->op('invoices.create', $body);
        $api->op('invoices.create', $body, ['timeout' => 45]);
        self::assertSame([90.0, 45.0], $api->timeouts());
    }

    public function testKeepsTheClientTimeoutWhenNotWaiting(): void
    {
        $clock = new FakeClock();
        $api = self::api([Replies::timeoutAfter($clock, 5)], ['timeout' => 5.0, 'maxRetries' => 0], $clock);
        self::throws(
            TimeoutException::class,
            static fn() => $api->op('invoices.create', ['options' => ['waitTimeoutSeconds' => 30]]),
            '/timed out after 5 seconds/',
        );
        self::assertSame([5.0], $api->timeouts());
    }

    public function testSendsABodyThatIsAListAsItIs(): void
    {
        $api = self::api([Replies::json([], 202)]);
        $api->op('invoices.create', [1, 2]);
        self::assertSame('[1,2]', (string) $api->request(0)->getBody());
    }

    #[DataProvider('documentTypes')]
    public function testReturnsTheDocumentAnEarlierAttemptCreated(string $resource, string $path): void
    {
        $api = self::api([
            Replies::networkError('reset'),
            Replies::problem(422, 'idempotency_key_reused', ['documentId' => Replies::OTHER_ID]),
            Replies::json(Replies::document(Replies::OTHER_ID, 'authorized')),
        ]);
        self::assertSame(Replies::OTHER_ID, self::member($api->op("{$resource}.create", []), 'id'));
        self::assertSame(
            ["POST {$path}", "POST {$path}", 'GET /v1/documents/' . Replies::OTHER_ID],
            array_map(static fn($request): string => $request->getMethod() . ' ' . $request->getUri()->getPath(), $api->sent),
        );
        self::assertSame($api->request(0)->getHeaderLine('idempotency-key'), $api->request(1)->getHeaderLine('idempotency-key'));
    }

    #[DataProvider('documentTypes')]
    public function testReturnsTheResponseWithWithResponse(string $resource, string $path): void
    {
        $api = self::api([Replies::json(Replies::document(), 202, ['X-Request-Id' => 'req_issue'])]);
        $result = $api->opWithResponse("{$resource}.create", []);
        self::assertInstanceOf(ApiResponse::class, $result);
        self::assertSame(Replies::ID, self::member($result->data, 'id'));
        self::assertSame(202, $result->response->getStatusCode());
        self::assertSame('req_issue', $result->requestId);
        self::assertSame($path, $api->request(0)->getUri()->getPath());
    }

    private static function member(mixed $document, string $name): mixed
    {
        self::assertIsArray($document);

        return $document[$name] ?? null;
    }
}
