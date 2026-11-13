<?php

declare(strict_types=1);

namespace Flashpi;

use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Internal\ClientConfig;
use Flashpi\Internal\Engine;
use Flashpi\Internal\Validate;
use Flashpi\Resources\CreditNotes;
use Flashpi\Resources\DebitNotes;
use Flashpi\Resources\Documents;
use Flashpi\Resources\Events;
use Flashpi\Resources\Invoices;
use Flashpi\Resources\Issuers;
use Flashpi\Resources\PurchaseSettlements;
use Flashpi\Resources\StatusResource;
use Flashpi\Resources\Waybills;
use Flashpi\Resources\Withholdings;
use Flashpi\Types\SdkTypes;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * The Flashpi API client.
 *
 * ```php
 * use Flashpi\Flashpi;
 *
 * $flashpi = new Flashpi(); // reads FLASHPI_API_KEY
 * $invoice = $flashpi->invoices->create([...]);
 * ```
 *
 * An `fp_test_` key issues in the SRI test environment (pruebas); an `fp_live_` key issues real
 * documents in production. Use it on servers only: the key must stay secret. The client never
 * shows it: not in var_dump(), print_r(), var_export(), exceptions or stack traces.
 *
 * @phpstan-import-type Language from SdkTypes
 * @phpstan-import-type WebhookEvent from SdkTypes
 * @phpstan-import-type WebhookHeaders from SdkTypes
 */
final class Flashpi
{
    public const DEFAULT_BASE_URL = 'https://api.flashpi.com.ec';

    /**
     * Seconds each attempt of a request may take, from sending it to reading the whole answer
     * (the Node SDK's 60 000 ms).
     */
    public const DEFAULT_TIMEOUT = 60.0;

    public const DEFAULT_MAX_RETRIES = 2;

    /**
     * `test` for fp_test_ keys (SRI pruebas), `live` for fp_live_ keys (SRI production).
     *
     * @var 'test'|'live'
     */
    public readonly string $environment;

    /** Invoices (facturas). */
    public readonly Invoices $invoices;

    /** Credit notes (notas de crédito). */
    public readonly CreditNotes $creditNotes;

    /** Debit notes (notas de débito). */
    public readonly DebitNotes $debitNotes;

    /** Purchase settlements (liquidaciones de compra). */
    public readonly PurchaseSettlements $purchaseSettlements;

    /** Waybills (guías de remisión). */
    public readonly Waybills $waybills;

    /** Withholding receipts (comprobantes de retención). */
    public readonly Withholdings $withholdings;

    /** Every issued document, of any type: search, XML, RIDE (PDF) and email. */
    public readonly Documents $documents;

    /**
     * The issuers (RUCs) of the account, with their establishments, emission points and .p12
     * certificates.
     */
    public readonly Issuers $issuers;

    /** Events: what happened to documents, certificates and email sending. */
    public readonly Events $events;

    /** The API's own status. */
    public readonly StatusResource $status;

    /** Verify the signature of the webhooks you receive (no API key needed). */
    public readonly Webhooks $webhooks;

    private readonly Engine $engine;

    /**
     * Creates a client. Pass the settings by name: `new Flashpi(apiKey: ..., timeout: 30)`.
     *
     * @param string|null $apiKey The API key (`fp_test_...` or `fp_live_...`). Defaults to the
     *                            FLASHPI_API_KEY environment variable.
     * @param string|null $baseUrl The API's address (default https://api.flashpi.com.ec).
     * @param float|null $timeout Seconds each attempt of a request may take, from sending it to
     *                            reading the whole answer (default 60). Issuing with
     *                            `options.waitForAuthorization` raises it to the wait plus 15 s.
     * @param int|null $maxRetries Retries after network errors, timeouts, 408, 429 and 5xx
     *                             (default 2; 0 turns them off).
     * @param Language|null $language Language of the API's messages: `en` (default) or `es`.
     *                                Error codes do not change.
     * @param array<string, string>|null $defaultHeaders Headers sent with every request (not
     *                                                   `Idempotency-Key`: that one is per
     *                                                   request).
     * @param ClientInterface|null $httpClient A Guzzle client to send the requests with (proxies,
     *                                         middleware, tests). The SDK never follows
     *                                         redirects and sets each attempt's timeout on it.
     *
     * @throws FlashpiException A missing or malformed API key, or a setting out of range.
     * @throws \InvalidArgumentException A default header or language that cannot be sent.
     */
    public function __construct(
        #[\SensitiveParameter]
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?float $timeout = null,
        ?int $maxRetries = null,
        ?string $language = null,
        ?array $defaultHeaders = null,
        ?ClientInterface $httpClient = null,
    ) {
        // The key is checked first, so a missing or bad key is the error reported.
        $config = new ClientConfig(
            Validate::apiKey($apiKey),
            Validate::baseUrl($baseUrl ?? self::DEFAULT_BASE_URL),
            Validate::timeout('timeout', $timeout ?? self::DEFAULT_TIMEOUT),
            Validate::retries('maxRetries', $maxRetries ?? self::DEFAULT_MAX_RETRIES),
            $language === null ? null : Validate::headerValue('language', $language),
            self::defaultHeaders($defaultHeaders),
        );
        $this->environment = $config->environment();
        $this->engine = new Engine($config, $httpClient ?? new Client(['allow_redirects' => false, 'http_errors' => false]));
        $this->invoices = new Invoices($this->engine);
        $this->creditNotes = new CreditNotes($this->engine);
        $this->debitNotes = new DebitNotes($this->engine);
        $this->purchaseSettlements = new PurchaseSettlements($this->engine);
        $this->waybills = new Waybills($this->engine);
        $this->withholdings = new Withholdings($this->engine);
        $this->documents = new Documents($this->engine);
        $this->issuers = new Issuers($this->engine);
        $this->events = new Events($this->engine);
        $this->status = new StatusResource($this->engine);
        $this->webhooks = new Webhooks();
    }

    /**
     * Verifies a webhook and returns its decoded body: see Webhooks::unwrap().
     *
     * @param string|\Stringable $payload
     * @param WebhookHeaders $headers
     * @param string $secret
     *
     * @return WebhookEvent
     *
     * @throws WebhookVerificationException
     */
    public static function unwrapWebhook(
        mixed $payload,
        iterable $headers,
        #[\SensitiveParameter]
        mixed $secret,
        int|float|null $tolerance = null,
        \DateTimeInterface|int|float|null $now = null,
    ): array {
        return (new Webhooks())->unwrap($payload, $headers, $secret, $tolerance, $now);
    }

    /**
     * Checks that a webhook comes from Flashpi and is recent: see Webhooks::verifySignature().
     *
     * @param string|\Stringable $payload
     * @param WebhookHeaders $headers
     * @param string $secret
     *
     * @throws WebhookVerificationException
     */
    public static function verifyWebhookSignature(
        mixed $payload,
        iterable $headers,
        #[\SensitiveParameter]
        mixed $secret,
        int|float|null $tolerance = null,
        \DateTimeInterface|int|float|null $now = null,
    ): void {
        (new Webhooks())->verifySignature($payload, $headers, $secret, $tolerance, $now);
    }

    /**
     * @return array{environment: 'test'|'live', baseUrl: string}
     */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment, 'baseUrl' => $this->engine->config()->baseUrl];
    }

    /**
     * @param array<mixed>|null $headers
     *
     * @return array<string, string>
     */
    private static function defaultHeaders(?array $headers): array
    {
        $checked = Validate::headers('defaultHeaders', $headers);
        foreach (array_keys($checked) as $name) {
            if (strtolower($name) === 'idempotency-key') {
                throw new FlashpiException(
                    "defaultHeaders cannot carry an Idempotency-Key: it must be unique per operation. Pass the idempotencyKey option to each create() instead",
                );
            }
        }

        return $checked;
    }
}
