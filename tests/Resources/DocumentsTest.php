<?php

declare(strict_types=1);

namespace Flashpi\Tests\Resources;

use Flashpi\Exception\ConflictException;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `$flashpi->documents` (Python: tests/test_resources.py's TestDocuments).
 */
final class DocumentsTest extends TestCase
{
    private const DOCUMENT = 'doc_1';

    public function testListsDocumentsWithTheirFilters(): void
    {
        $api = self::api([Replies::page([Replies::document()])]);
        $page = $api->client->documents->list(['status' => 'authorized', 'issueDateFrom' => '2026-11-01', 'limit' => 50]);
        self::assertSame([Replies::ID], array_column($page->data, 'id'));
        self::assertSentOnly($api, 'GET', '/v1/documents', ['status' => 'authorized', 'issueDateFrom' => '2026-11-01', 'limit' => '50']);
    }

    public function testListsDocumentsWithoutFilters(): void
    {
        $api = self::api([Replies::page([])]);
        $api->client->documents->list();
        self::assertSentOnly($api, 'GET', '/v1/documents');
    }

    public function testGetsADocument(): void
    {
        $api = self::api([Replies::json(Replies::document(self::DOCUMENT))]);
        self::assertSame(self::DOCUMENT, $api->client->documents->get(self::DOCUMENT)['id']);
        self::assertSentOnly($api, 'GET', '/v1/documents/' . self::DOCUMENT);
    }

    public function testSendsTheEmailAgainWithAnEmptyBody(): void
    {
        $api = self::api([Replies::json(Replies::document(self::DOCUMENT), 202)]);
        self::assertSame(self::DOCUMENT, $api->client->documents->sendEmail(self::DOCUMENT)['id']);
        self::assertSentOnly($api, 'POST', '/v1/documents/' . self::DOCUMENT . '/email', json: '{}');
    }

    public function testReturnsTheXmlAndWhichStageItIs(): void
    {
        $api = self::api([
            new Response(200, ['Content-Type' => 'application/xml', 'Flashpi-Xml-Kind' => 'authorized'], '<autorizacion/>'),
            new Response(200, ['Content-Type' => 'application/xml'], '<factura/>'),
            new Response(200, ['Flashpi-Xml-Kind' => 'draft'], '<factura/>'),
        ]);
        self::assertSame(['xml' => '<autorizacion/>', 'kind' => 'authorized'], $api->client->documents->xml(self::DOCUMENT));
        self::assertSame('/v1/documents/' . self::DOCUMENT . '/xml', $api->request(0)->getUri()->getPath());
        self::assertSame('application/xml, application/problem+json', $api->request(0)->getHeaderLine('accept'));
        $result = $api->client->documents->withResponse()->xml(self::DOCUMENT);
        self::assertSame(['xml' => '<factura/>', 'kind' => null], $result->data);
        self::assertNull($result->requestId);
        self::assertSame(200, $result->response->getStatusCode());
        self::assertNull($api->client->documents->xml(self::DOCUMENT)['kind']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function xmlKinds(): iterable
    {
        yield 'authorized' => ['authorized'];
        yield 'signed' => ['signed'];
        yield 'unsigned' => ['unsigned'];
    }

    #[DataProvider('xmlKinds')]
    public function testReadsEveryXmlStage(string $kind): void
    {
        $api = self::api([new Response(200, ['Flashpi-Xml-Kind' => $kind], '<x/>')]);
        self::assertSame($kind, $api->client->documents->xml(self::DOCUMENT)['kind']);
    }

    public function testReturnsTheRideAsBytes(): void
    {
        $pdf = "%PDF\x00\xff";
        $api = self::api([new Response(200, ['Content-Type' => 'application/pdf'], $pdf)]);
        self::assertSame($pdf, $api->client->documents->pdf(self::DOCUMENT));
        self::assertSame('/v1/documents/' . self::DOCUMENT . '/pdf', $api->request(0)->getUri()->getPath());
        self::assertSame('application/pdf, application/problem+json', $api->request(0)->getHeaderLine('accept'));
    }

    public function testThrowsRideNotAvailableAsAConflictWithTheRideStatus(): void
    {
        $api = self::api([Replies::problem(409, 'ride_not_available', ['rideStatus' => 'pending'])]);
        $error = self::throws(ConflictException::class, static fn() => $api->client->documents->pdf(self::DOCUMENT));
        self::assertSame('ride_not_available', $error->errorCode);
        self::assertSame('pending', $error->problem['rideStatus'] ?? null);
        self::assertCount(1, $api->sent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function methods(): iterable
    {
        yield 'list' => ['list'];
        yield 'get' => ['get'];
        yield 'pdf' => ['pdf'];
        yield 'sendEmail' => ['sendEmail'];
    }

    #[DataProvider('methods')]
    public function testReturnsTheResponseWithWithResponse(string $method): void
    {
        $api = self::api([$method === 'list' ? Replies::page([]) : Replies::json([], 202)]);
        $result = $api->opWithResponse("documents.{$method}", ...($method === 'list' ? [] : [self::DOCUMENT]));
        self::assertSame('req_123', $result->requestId);
    }
}
