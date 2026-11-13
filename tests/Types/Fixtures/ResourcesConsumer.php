<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types\Fixtures;

use Flashpi\ApiResponse;
use Flashpi\Exception\ApiException;
use Flashpi\Exception\ConflictException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\UnprocessableEntityException;
use Flashpi\Flashpi;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Every resource used the way the README's examples use it (Python:
 * tests/test_resource_types.py). PHPStan analyses it with the rest of the tests, at level max:
 * all of it must pass, and each method's declared return type proves what a call returns. Its
 * counterpart, Invalid/ResourcesMisuse.php, must not pass. It is never run.
 *
 * @phpstan-import-type Certificate from ApiTypes
 * @phpstan-import-type CompletedIssuerOnboarding from ApiTypes
 * @phpstan-import-type CreateCreditNote from ApiTypes
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 * @phpstan-import-type EmissionPoint from ApiTypes
 * @phpstan-import-type Establishment from ApiTypes
 * @phpstan-import-type Event from ApiTypes
 * @phpstan-import-type Issuer from ApiTypes
 * @phpstan-import-type IssuerOnboarding from ApiTypes
 * @phpstan-import-type Status from ApiTypes
 * @phpstan-import-type WebhookDelivery from ApiTypes
 * @phpstan-import-type WebhookDeliveryDetail from ApiTypes
 * @phpstan-import-type WebhookEndpoint from ApiTypes
 * @phpstan-import-type WebhookEndpointSecret from ApiTypes
 * @phpstan-import-type CertificateUpload from SdkTypes
 * @phpstan-import-type DocumentXml from SdkTypes
 */
final class ResourcesConsumer
{
    /**
     * @return CreateInvoice
     */
    public static function invoice(string $issuerId): array
    {
        return [
            'issuerId' => $issuerId,
            'establishment' => '001',
            'emissionPoint' => '001',
            'customer' => [
                'idType' => 'ruc',
                'id' => '1760013210001',
                'name' => 'PRUEBAS SERVICIO DE RENTAS INTERNAS',
                'address' => 'Av. Amazonas y Villalengua, Quito',
            ],
            'items' => [
                [
                    'code' => 'P-001',
                    'description' => 'Test service',
                    'quantity' => '1',
                    'unitPrice' => '1.00',
                    'taxes' => [['type' => 'vat', 'rate' => '15']],
                ],
            ],
            'payments' => [['method' => 'cash', 'amount' => '1.15']],
            'options' => ['waitForAuthorization' => true],
        ];
    }

    /**
     * @param CreateCreditNote $note
     *
     * @return list<Document>
     */
    public static function issue(Flashpi $flashpi, string $issuerId, array $note, string $orderId): array
    {
        $invoice = $flashpi->invoices->create(self::invoice($issuerId));
        $again = $flashpi->invoices->create(self::invoice($issuerId), ['idempotencyKey' => "order-{$orderId}", 'timeout' => 90]);
        $credit = $flashpi->creditNotes->create($note);

        return [$invoice, $again, $credit];
    }

    /**
     * @return ApiResponse<Document>
     */
    public static function issueWithResponse(Flashpi $flashpi, string $issuerId): ApiResponse
    {
        $result = $flashpi->invoices->withResponse()->create(self::invoice($issuerId));
        echo $result->data['status'], $result->response->getStatusCode(), $result->requestId ?? '-';

        return $result;
    }

    /**
     * @return array{Document, DocumentXml, string, Document}
     */
    public static function documents(Flashpi $flashpi, string $documentId): array
    {
        $document = $flashpi->documents->get($documentId, ['timeout' => 10, 'language' => 'es', 'correlationId' => 'order-42']);
        $xml = $flashpi->documents->xml($documentId);
        if ($xml['kind'] === 'authorized') {
            echo $xml['xml'];
        }
        $pdf = $flashpi->documents->pdf($documentId);
        file_put_contents("{$document['accessKey']}.pdf", $pdf);
        $emailed = $flashpi->documents->sendEmail($documentId);

        return [$document, $xml, $pdf, $emailed];
    }

    /**
     * @return list<string>
     */
    public static function accessKeys(Flashpi $flashpi): array
    {
        $keys = [];
        foreach ($flashpi->documents->list(['status' => 'authorized', 'issueDateFrom' => '2026-11-01']) as $document) {
            $keys[] = $document['accessKey'];
        }
        $page = $flashpi->documents->list(['limit' => 50]);
        echo \count($page->data);
        if ($page->hasNextPage()) {
            $page = $page->nextPage();
        }

        return [...$keys, ...array_column($page->data ?? [], 'accessKey')];
    }

    /**
     * @return Page<Document>
     */
    public static function documentsOfAnIssuer(Flashpi $flashpi, string $issuerId): Page
    {
        return $flashpi->documents->list(['issuerId' => $issuerId, 'type' => 'invoice']);
    }

    public static function errors(Flashpi $flashpi, string $issuerId): string
    {
        try {
            $flashpi->invoices->create(self::invoice($issuerId));
        } catch (UnprocessableEntityException $error) {
            $fields = array_map(static fn(array $field): string => "{$field['field']}: {$field['message']}", $error->errors);

            return ($error->errorCode ?? '') . ' ' . implode(', ', $fields);
        } catch (ApiException $error) {
            return "{$error->status} {$error->errorCode} {$error->requestId}";
        } catch (FlashpiException $error) {
            return $error->idempotencyKey ?? $error->getMessage();
        }

        return 'issued';
    }

    public static function ride(Flashpi $flashpi, string $documentId): ?string
    {
        try {
            return $flashpi->documents->pdf($documentId);
        } catch (ConflictException $error) {
            if ($error->errorCode !== 'ride_not_available') {
                throw $error;
            }

            return null;
        }
    }

    /**
     * @return array{IssuerOnboarding, CompletedIssuerOnboarding, list<string>}
     */
    public static function onboarding(Flashpi $flashpi): array
    {
        $file = fopen('firma.p12', 'rb');
        if ($file === false) {
            throw new \RuntimeException('No firma.p12');
        }
        $onboarding = $flashpi->issuerOnboardings->create(['file' => $file, 'password' => (string) getenv('P12_PASSWORD')]);
        while ($onboarding['status'] === 'verifying') {
            sleep(2);
            $onboarding = $flashpi->issuerOnboardings->get($onboarding['id']);
        }
        if ($onboarding['status'] !== 'ready') {
            throw new \RuntimeException($onboarding['rejection']['message'] ?? $onboarding['status']);
        }

        $prefill = $onboarding['prefill'];
        if ($onboarding['existingIssuer'] !== null) {
            // A RUC you already have: the certificate is added to that issuer (a renewal).
            $completed = $flashpi->issuerOnboardings->complete($onboarding['id']);
        } else {
            // A new RUC: confirm the issuer's data, starting from the prefill.
            $taxProfile = $prefill['taxProfile'] ?? null;
            $rimpeRegime = $taxProfile['rimpeRegime'] ?? null;
            $completed = $flashpi->issuerOnboardings->complete($onboarding['id'], [
                'legalName' => $prefill['legalName'] ?? 'MY COMPANY S.A.',
                'headOfficeAddress' => $prefill['headOfficeAddress'] ?? 'Av. Amazonas N24-100, Quito',
                'taxProfile' => [
                    'keepsAccounting' => $taxProfile['keepsAccounting'] ?? false,
                    'rimpeRegime' => $rimpeRegime === 'general' ? null : $rimpeRegime,
                ],
            ]);
        }

        $codes = [];
        foreach ($flashpi->issuers->establishments->list($completed['issuer']['id']) as $establishment) {
            $codes[] = $establishment['code'];
        }

        return [$onboarding, $completed, $codes];
    }

    /**
     * @param CertificateUpload $upload
     *
     * @return array{Certificate, Certificate, ApiResponse<Certificate>}
     */
    public static function certificates(Flashpi $flashpi, string $issuerId, array $upload): array
    {
        $fromContents = $flashpi->issuers->certificates->upload($issuerId, ['file' => (string) file_get_contents('firma.p12'), 'password' => 'secret', 'filename' => 'firma.p12']);
        $certificate = $flashpi->issuers->certificates->get($issuerId, $fromContents['id']);
        $result = $flashpi->issuers->certificates->withResponse()->upload($issuerId, $upload);

        return [$fromContents, $certificate, $result];
    }

    /**
     * @return array{Issuer, Establishment, EmissionPoint, ApiResponse<Page<Issuer>>, Page<Certificate>}
     */
    public static function issuers(Flashpi $flashpi, string $issuerId): array
    {
        $issuer = $flashpi->issuers->update($issuerId, ['tradeName' => 'Panadería Flashpi']);
        $establishment = $flashpi->issuers->establishments->create($issuerId, ['code' => '002', 'address' => 'Quito']);
        $point = $flashpi->issuers->establishments->emissionPoints->create($issuerId, $establishment['id'], ['code' => '001']);
        $flashpi->issuers->establishments->emissionPoints->delete($issuerId, $establishment['id'], $point['id']);
        $flashpi->issuers->establishments->delete($issuerId, $establishment['id']);
        $issuers = $flashpi->issuers->withResponse()->list(['limit' => 10]);
        $certificates = $flashpi->issuers->certificates->list($issuerId);

        return [$issuer, $establishment, $point, $issuers, $certificates];
    }

    /**
     * @return array{WebhookEndpoint, WebhookEndpointSecret, WebhookEndpoint, list<WebhookDelivery>, WebhookDeliveryDetail}
     */
    public static function webhookEndpoints(Flashpi $flashpi): array
    {
        $endpoint = $flashpi->webhookEndpoints->create([
            'url' => 'https://example.com/webhooks/flashpi',
            'enabledEvents' => ['invoice.authorized', 'invoice.rejected'],
        ]);
        $secret = $flashpi->webhookEndpoints->getSecret($endpoint['id']);
        $verified = $flashpi->webhookEndpoints->verify($endpoint['id']);
        $flashpi->webhookEndpoints->rotateSecret($endpoint['id'], ['previousSecretExpiresInHours' => 0]);
        $flashpi->webhookEndpoints->update($endpoint['id'], ['disabled' => true]);
        $failed = [];
        foreach ($flashpi->webhookEndpoints->deliveries->list($endpoint['id'], ['status' => 'failed']) as $delivery) {
            $failed[] = $delivery;
        }
        $replayed = $flashpi->webhookEndpoints->deliveries->replay($endpoint['id'], $failed[0]['id'] ?? 'none');
        echo $flashpi->webhookEndpoints->withResponse()->delete($endpoint['id'])->response->getStatusCode();

        return [$endpoint, $secret, $verified, $failed, $replayed];
    }

    /**
     * @return array{Event, Status, ApiResponse<Status>, list<string>}
     */
    public static function eventsAndStatus(Flashpi $flashpi, string $documentId): array
    {
        $types = [];
        foreach ($flashpi->events->list(['documentId' => $documentId]) as $event) {
            $types[] = $event['type'];
        }
        $event = $flashpi->events->get('evt_1');
        $status = $flashpi->status->get(['language' => 'es']);
        if ($status['status'] === 'operational') {
            echo 'up';
        }

        return [$event, $status, $flashpi->status->withResponse()->get(), $types];
    }
}
