<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types\Fixtures;

use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Code written the way a consumer of the SDK writes it. PHPStan analyses it with the rest of the
 * tests, at level max: all of it must pass. Its counterpart, Invalid/TypesMisuse.php, must not.
 *
 * @phpstan-import-type Certificate from ApiTypes
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 * @phpstan-import-type ErrorCode from ApiTypes
 * @phpstan-import-type FieldError from ApiTypes
 * @phpstan-import-type FieldErrorCode from ApiTypes
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

    /**
     * @param Certificate $certificate
     *
     * @return list<string>
     */
    public static function warnings(array $certificate): array
    {
        return array_map(
            static fn(array $warning): string => match ($warning['code']) {
                'ruc_mismatch' => "Test documents only: {$warning['message']}",
            },
            $certificate['warnings'],
        );
    }

    /**
     * @param Document $document
     */
    public static function failure(array $document): ?string
    {
        return match ($document['failure']['code'] ?? null) {
            'certificate_ruc_mismatch' => "Upload a certificate of the issuer's RUC.",
            'sri_authorization_mismatch' => 'The SRI authorized another document: contact support.',
            default => null,
        };
    }

    /**
     * The codes the API lists are named in ErrorCode and FieldErrorCode, besides being open.
     *
     * @param ErrorCode $code
     */
    public static function advice(string $code): ?string
    {
        return match ($code) {
            'live_key_required' => 'The issuer has live documents: use an fp_live_ key.',
            'webhook_endpoint_limit_reached' => 'Delete a webhook endpoint first.',
            default => null,
        };
    }

    /**
     * @param FieldErrorCode $code
     */
    public static function fieldAdvice(string $code): ?string
    {
        return match ($code) {
            'demo_issuer_free_text_not_allowed' => 'Leave out the additional fields with the demo issuer.',
            'demo_issuer_description_too_long' => 'Keep descriptions to 291 characters with the demo issuer.',
            'certificate_ruc_missing' => 'Use a certificate that states the RUC or the cédula.',
            default => null,
        };
    }
}
