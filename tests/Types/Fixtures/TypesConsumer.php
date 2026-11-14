<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types\Fixtures;

use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Code written the way a consumer of the SDK writes it. PHPStan analyses it with the rest of the
 * tests, at level max: all of it must pass. Its counterpart, Invalid/TypesMisuse.php, must not.
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 * @phpstan-import-type FieldError from ApiTypes
 * @phpstan-import-type DocumentListParams from SdkTypes
 * @phpstan-import-type Problem from SdkTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 * @phpstan-import-type ThinWebhookEvent from SdkTypes
 * @phpstan-import-type WebhookEvent from SdkTypes
 */
final class TypesConsumer
{
    /**
     * @return CreateInvoice
     */
    public static function invoice(string $issuerId): array
    {
        $item = [
            'code' => 'PAN-01',
            'description' => 'Pan de yuca',
            'quantity' => '2',
            'unitPrice' => '0.50',
            'taxes' => [['type' => 'vat', 'rate' => '15']],
        ];

        return [
            'issuerId' => $issuerId,
            'establishment' => '001',
            'emissionPoint' => '001',
            'customer' => ['idType' => 'final_consumer'],
            'items' => [$item],
            'payments' => [['method' => 'cash', 'amount' => '1.15']],
            'options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 20],
        ];
    }

    /**
     * @return RequestOptions
     */
    public static function options(): array
    {
        return ['idempotencyKey' => 'order-42', 'timeout' => 30, 'language' => 'es', 'extraHeaders' => ['X-Tenant' => 'acme']];
    }

    /**
     * @return DocumentListParams
     */
    public static function filters(): array
    {
        return ['status' => 'authorized', 'issueDateFrom' => '2026-11-01', 'limit' => 50];
    }

    /**
     * @param Document $document
     */
    public static function describe(array $document): string
    {
        $label = match ($document['status']) {
            'processing', 'pending_sri' => 'waiting',
            'authorized' => 'valid',
            'rejected', 'failed', 'voided' => 'not valid',
        };

        return "{$document['number']} ({$document['accessKey']}): {$label}";
    }

    /**
     * @param WebhookEvent $event
     *
     * @return array{challenge: string}|null
     */
    public static function handle(array $event): ?array
    {
        if ($event['type'] === 'webhook_endpoint.verification') {
            return ['challenge' => $event['data']['challenge']];
        }
        if ($event['type'] === 'invoice.authorized') {
            // Narrowed to a DocumentEvent: its data is the document.
            return ['challenge' => self::describe($event['data'])];
        }

        return null;
    }

    /**
     * @param ThinWebhookEvent $event
     */
    public static function thin(array $event): string
    {
        return $event['id'];
    }

    /**
     * Codes are open: a code this version does not know yet still compiles.
     *
     * @param Problem $problem
     */
    public static function retryable(array $problem): bool
    {
        return $problem['code'] === 'a_code_added_later' || ($problem['documentId'] ?? '') !== '';
    }

    /**
     * @param list<FieldError> $errors
     *
     * @return list<string>
     */
    public static function fields(array $errors): array
    {
        return array_map(static fn(array $error): string => "{$error['field']}: {$error['code']}", $errors);
    }
}
