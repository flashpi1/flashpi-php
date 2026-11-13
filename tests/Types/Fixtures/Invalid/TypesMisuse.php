<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types\Fixtures\Invalid;

use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * Mistakes PHPStan must report at level max (tests/Types/ConsumerTypesTest.php checks each one by
 * its line). Excluded from the main analysis.
 *
 * @phpstan-import-type CreateInvoice from ApiTypes
 * @phpstan-import-type Document from ApiTypes
 * @phpstan-import-type RequestOptions from SdkTypes
 */
final class TypesMisuse
{
    /**
     * @param CreateInvoice $invoice
     */
    public static function issue(array $invoice): void {}

    /**
     * @param RequestOptions $options
     */
    public static function options(array $options): void {}

    /**
     * @param Document $document
     */
    public static function read(array $document): string
    {
        return $document['acessKey'];
    }

    public static function mistakes(): void
    {
        $base = [
            'issuerId' => 'x',
            'establishment' => '001',
            'emissionPoint' => '001',
            'customer' => ['idType' => 'final_consumer'],
            'items' => [],
            'payments' => [],
        ];
        self::issue([...$base, 'expectedTotl' => '1.15']);
        self::issue([...$base, 'customer' => ['idType' => 'dni']]);
        self::issue(['issuerId' => 'x', 'establishment' => '001', 'emissionPoint' => '001', 'items' => [], 'payments' => []]);
        self::issue([...$base, 'sequential' => '7']);
        self::options(['idempotencykey' => 'order-1']);
        self::options(['language' => 'fr']);
    }

    public static function client(\Flashpi\Exception\ApiException $error, string $body): string
    {
        new \Flashpi\Flashpi(language: 'fr');
        $event = \Flashpi\Flashpi::unwrapWebhook($body, [], 'whsec_x');

        return $event['data']['challenge'] . $error->code;
    }
}
