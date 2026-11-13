<?php

declare(strict_types=1);

namespace Flashpi\Tests\Live;

use Flashpi\Exception\ConflictException;
use Flashpi\Exception\NotFoundException;
use Flashpi\Exception\UnprocessableEntityException;
use Flashpi\Flashpi;
use Flashpi\Types\ApiTypes;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The SDK against the real Flashpi API in test mode (SRI pruebas), port of the Python SDK's
 * tests/live/test_live.py and the Node SDK's test/live/live.test.ts:
 *
 *     composer test:live
 *
 * Needs FLASHPI_TEST_API_KEY (an fp_test_ key with read_write access) and FLASHPI_DEMO_ISSUER_ID
 * (the demo issuer's id, on the console's Overview); FLASHPI_BASE_URL may point to another API.
 * Without them every test is skipped, and the normal run leaves the `live` group out. It issues
 * ONE real test invoice on the shared demo issuer, which counts toward its daily quota of 50, so
 * CI only runs it while a release is being prepared. It never accepts an fp_live_ key.
 *
 * The tests run in order and share the invoice.
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 */
#[Group('live')]
final class LiveTest extends TestCase
{
    /** Checks of a slow SRI step, 5 seconds apart: up to 2 minutes. */
    private const POLLS = 24;

    private const POLL_SECONDS = 5;

    private static ?string $idempotencyKey = null;

    protected function setUp(): void
    {
        if (self::apiKey() === '' || self::issuerId() === '') {
            self::markTestSkipped('needs FLASHPI_TEST_API_KEY and FLASHPI_DEMO_ISSUER_ID');
        }
    }

    public function testReadsTheApiStatus(): void
    {
        self::assertSame(['status' => 'operational'], self::client()->status->get());
    }

    public function testRejectsAnInvalidInvoiceWithFieldErrorsInSpanish(): void
    {
        $body = self::invoice();
        $body['items'] = [];
        try {
            self::client()->invoices->create($body, ['language' => 'es', 'maxRetries' => 0]);
            self::fail('The invalid invoice was issued');
        } catch (UnprocessableEntityException $problem) {
            self::assertSame('validation_failed', $problem->errorCode);
            self::assertNotEmpty($problem->errors);
            self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string) $problem->requestId);
            self::assertSame('es', $problem->header('content-language'));
        }
    }

    /**
     * @return Document
     */
    public function testIssuesATestInvoiceAndWaitsForTheSri(): array
    {
        $flashpi = self::client();
        $result = $flashpi->invoices->withResponse()->create(self::invoice(), [
            'idempotencyKey' => self::idempotencyKey(),
            'correlationId' => 'flashpi-php-live',
        ]);
        self::assertContains($result->response->getStatusCode(), [200, 202]);
        self::assertNotEmpty($result->requestId);
        self::assertSame('invoice', $result->data['type']);
        self::assertSame('test', $result->data['environment']);
        $document = $result->data;

        // The SRI's test environment can be slow: follow the document for up to 2 minutes.
        for ($poll = 0; $poll < self::POLLS && \in_array($document['status'], ['processing', 'pending_sri'], true); $poll++) {
            sleep(self::POLL_SECONDS);
            $document = $flashpi->documents->get($document['id']);
        }
        self::assertSame('authorized', $document['status']);
        self::assertMatchesRegularExpression('/^\d{49}$/', $document['accessKey']);

        return $document;
    }

    /**
     * @param Document $invoice
     */
    #[Depends('testIssuesATestInvoiceAndWaitsForTheSri')]
    public function testReplaysTheSameIdempotencyKeyInsteadOfIssuingAgain(array $invoice): void
    {
        $result = self::client()->invoices->withResponse()->create(self::invoice(), ['idempotencyKey' => self::idempotencyKey()]);
        self::assertSame($invoice['id'], $result->data['id']);
        self::assertSame('true', $result->response->getHeaderLine('idempotent-replayed'));
    }

    /**
     * @param Document $invoice
     */
    #[Depends('testIssuesATestInvoiceAndWaitsForTheSri')]
    public function testFindsTheDocumentByItsAccessKey(array $invoice): void
    {
        $page = self::client()->documents->list(['accessKey' => $invoice['accessKey'], 'limit' => 5]);
        self::assertSame([$invoice['id']], array_column($page->data, 'id'));
    }

    /**
     * @param Document $invoice
     */
    #[Depends('testIssuesATestInvoiceAndWaitsForTheSri')]
    public function testDownloadsTheAuthorizedXml(array $invoice): void
    {
        $xml = self::client()->documents->xml($invoice['id']);
        self::assertSame('authorized', $xml['kind']);
        self::assertStringContainsString($invoice['accessKey'], $xml['xml']);
    }

    /**
     * @param Document $invoice
     */
    #[Depends('testIssuesATestInvoiceAndWaitsForTheSri')]
    public function testDownloadsTheRideOnceItIsGenerated(array $invoice): void
    {
        $flashpi = self::client();
        $pdf = null;
        for ($poll = 0; $poll < self::POLLS && $pdf === null; $poll++) {
            try {
                $pdf = $flashpi->documents->pdf($invoice['id']);
            } catch (ConflictException $error) {
                if ($error->errorCode !== 'ride_not_available') {
                    throw $error;
                }
                sleep(self::POLL_SECONDS);
            }
        }
        self::assertNotNull($pdf);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    /**
     * @param Document $invoice
     */
    #[Depends('testIssuesATestInvoiceAndWaitsForTheSri')]
    public function testListsTheEventsOfTheDocument(array $invoice): void
    {
        $flashpi = self::client();
        $types = [];
        for ($poll = 0; $poll < 12 && !\in_array('invoice.authorized', $types, true); $poll++) {
            if ($poll > 0) {
                sleep(self::POLL_SECONDS);
            }
            $types = [];
            foreach ($flashpi->events->list(['documentId' => $invoice['id']]) as $event) {
                $types[] = $event['type'];
                $data = $event['data'];
                self::assertSame($invoice['id'], $data['id'] ?? null);
            }
        }
        self::assertContains('invoice.authorized', $types);
    }

    public function testAnswers404ForADocumentThatDoesNotExist(): void
    {
        $this->expectException(NotFoundException::class);
        self::client()->documents->get('01a00000-0000-7000-8000-000000000000', ['maxRetries' => 0]);
    }

    private static function client(): Flashpi
    {
        if (!str_starts_with(self::apiKey(), 'fp_test_')) {
            throw new \RuntimeException('FLASHPI_TEST_API_KEY must be an fp_test_ key: this suite never runs live');
        }
        $baseUrl = getenv('FLASHPI_BASE_URL');

        return new Flashpi(apiKey: self::apiKey(), baseUrl: \is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null);
    }

    /**
     * The same test invoice as the docs' quickstart: USD 1.00 + 15 % VAT to the SRI's test RUC.
     *
     * @return CreateInvoice
     */
    private static function invoice(): array
    {
        return [
            'issuerId' => self::issuerId(),
            'establishment' => '001',
            'emissionPoint' => '001',
            'customer' => [
                'idType' => 'ruc',
                'id' => '1760013210001',
                'name' => 'PRUEBAS SERVICIO DE RENTAS INTERNAS',
                'address' => 'AMAZONAS AVENUE AND VILLALENGUA, QUITO',
            ],
            'items' => [
                [
                    'code' => 'SDK-LIVE-001',
                    'description' => 'flashpi (PHP) live test',
                    'quantity' => '1',
                    'unitPrice' => '1.00',
                    'taxes' => [['type' => 'vat', 'rate' => '15']],
                ],
            ],
            'payments' => [['method' => 'cash', 'amount' => '1.15']],
            'options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 30, 'sendEmail' => false],
        ];
    }

    /**
     * One key for the whole run, so the replay test finds the invoice the suite issued.
     */
    private static function idempotencyKey(): string
    {
        return self::$idempotencyKey ??= \sprintf('sdk-live-php-%x-%s', time(), bin2hex(random_bytes(16)));
    }

    private static function apiKey(): string
    {
        return trim((string) getenv('FLASHPI_TEST_API_KEY'));
    }

    private static function issuerId(): string
    {
        return trim((string) getenv('FLASHPI_DEMO_ISSUER_ID'));
    }
}
