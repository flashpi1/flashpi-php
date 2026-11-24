# Flashpi for PHP

The official PHP SDK for [Flashpi](https://flashpi.com.ec), the electronic invoicing API for
Ecuador's SRI.

[Español](https://github.com/flashpi1/flashpi-php/blob/main/README.md)

- The 45 operations of the API: the six document types, documents (XML, RIDE PDF, email),
  issuers with establishments, emission points and .p12 certificates, webhooks and events.
- Requests and answers are arrays with the API's keys, typed with PHPStan array shapes generated
  from the API's OpenAPI document, so your editor and PHPStan know every field.
- Safe retries: every issuing request carries an `Idempotency-Key`, so a retry never issues a
  document twice.
- Typed errors (RFC 9457) with their `errorCode`, field errors and `requestId`.
- Automatic pagination with `foreach`.
- Webhook signature verification (Standard Webhooks) for Laravel, Symfony or plain PHP.
- PHP 8.3 or later. One dependency: [Guzzle](https://docs.guzzlephp.org/) 7.15.2 or later, or
  8.3 or later (Laravel 11, 12 and 13).

## Install

```bash
composer require flashpi/flashpi-php
```

## Getting started

Create an API key in the [console](https://flashpi.com.ec/app/) and keep it in the
`FLASHPI_API_KEY` environment variable. An `fp_test_` key issues in the SRI's test environment;
an `fp_live_` key issues real documents in production.

```php
use Flashpi\Flashpi;

// Reads FLASHPI_API_KEY; you can also pass it: new Flashpi(apiKey: 'fp_test_...')
$flashpi = new Flashpi();

$invoice = $flashpi->invoices->create([
    'issuerId' => '<the demo issuer id or yours>',
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
]);

echo $invoice['status'], ' ', $invoice['accessKey'], "\n";
```

Requests and answers are associative arrays with the API's own keys, in camelCase
(`$invoice['accessKey']`), typed with the PHPStan array shapes of `Flashpi\Types\ApiTypes`
(`@phpstan-import-type CreateInvoice from \Flashpi\Types\ApiTypes`). Amounts and quantities are
**strings** (`'1.15'`), never numbers, so no decimals are lost.

The last payment may leave out `amount`: Flashpi gives it what the other payments leave of the
total, as in
`'payments' => [['method' => 'credit_card', 'amount' => '10.00'], ['method' => 'cash']]`. With
several payments, none can be zero (`zero_payment`). See
[Totals](https://flashpi.com.ec/docs/en/documents/#totals).

With the **demo issuer** you can issue test documents without uploading a certificate: its id is
on the console's Overview (up to 50 documents a day). See
[Sandbox and demo issuer](https://flashpi.com.ec/docs/en/sandbox/).

Create one client and reuse it (in Laravel, register it as a singleton in a service provider).
On Laravel 12.71 or later, or 13.40 or later,
[flashpi/flashpi-laravel](https://github.com/flashpi1/flashpi-laravel/blob/main/README.en.md)
does it for you: it builds the client from `config/flashpi.php` and adds a facade, Artisan
commands and a ready webhook route that dispatches typed events. The manual way this README
describes still works (on Laravel 11, for example).

## Static analysis

The types are PHPStan array shapes. Turn on PHPStan's bleeding edge in your `phpstan.neon` so
that the shapes are sealed: without it, a misspelled optional key (`'expectedTotl'`) is not
reported.

```neon
includes:
    - vendor/phpstan/phpstan/conf/bleedingEdge.neon
```

With Guzzle 7, PHPStan reads `getallheaders()` as `mixed` (the polyfill Guzzle installs declares
no return type): pass `(array) getallheaders()` to the webhook methods there.

## Issuing documents

| Document                                    | Method                                         |
| ------------------------------------------- | ---------------------------------------------- |
| Invoice (factura)                           | `$flashpi->invoices->create($body)`            |
| Credit note (nota de crédito)               | `$flashpi->creditNotes->create($body)`         |
| Debit note (nota de débito)                 | `$flashpi->debitNotes->create($body)`          |
| Purchase settlement (liquidación de compra) | `$flashpi->purchaseSettlements->create($body)` |
| Waybill (guía de remisión)                  | `$flashpi->waybills->create($body)`            |
| Withholding (comprobante de retención)      | `$flashpi->withholdings->create($body)`        |

They all return the document. By default the answer comes right away with the status
`processing`, and the SRI's outcome arrives by webhook. With
`'options' => ['waitForAuthorization' => true]` the API waits for the SRI (15 s by default, up
to 30 s with `waitTimeoutSeconds`) and returns `authorized`, `rejected` or `failed`; if the SRI
takes longer, it returns `processing` or `pending_sri` and the document carries on.

## Documents

```php
$document = $flashpi->documents->get($invoice['id']);

$xml = $flashpi->documents->xml($invoice['id']); // $xml['kind']: 'authorized' once the SRI authorized it
$pdf = $flashpi->documents->pdf($invoice['id']); // the RIDE, as bytes
file_put_contents("{$invoice['accessKey']}.pdf", $pdf);

$flashpi->documents->sendEmail($invoice['id']); // emails the RIDE and XML to the buyer again
```

## Lists and pagination

A list call returns a page. A `foreach` loop walks every page, fetching them as you go:

```php
foreach ($flashpi->documents->list(['status' => 'authorized', 'issueDateFrom' => '2026-11-01']) as $document) {
    echo $document['accessKey'], "\n";
}

$page = $flashpi->documents->list(['limit' => 50]);
echo count($page->data), "\n"; // the documents of this page
if ($page->hasNextPage()) {
    $page = $page->nextPage();
}
```

The filters use the API's names (`issueDateFrom`, `issuerId`).

## Errors

Every API error is an `ApiException` subclass chosen by its HTTP status. Branch on `errorCode`,
which is stable and in English; the message follows the requested language.

```php
use Flashpi\Exception\ApiException;
use Flashpi\Exception\UnprocessableEntityException;

try {
    $flashpi->invoices->create($body);
} catch (UnprocessableEntityException $error) {
    echo $error->errorCode, "\n"; // 'validation_failed', 'total_mismatch', ...
    foreach ($error->errors as $field) {
        echo $field['field'], ': ', $field['message'], "\n";
    }
    throw $error;
} catch (ApiException $error) {
    echo $error->status, ' ', $error->errorCode, ' ', $error->requestId, "\n"; // share requestId with support
    throw $error;
}
```

| Class (`Flashpi\Exception`)    | Status | Example `errorCode`s                                        |
| ------------------------------ | ------ | ----------------------------------------------------------- |
| `BadRequestException`          | 400    | `malformed_json`, `invalid_idempotency_key`                 |
| `AuthenticationException`      | 401    | `authentication_required`, `invalid_api_key`                |
| `PermissionDeniedException`    | 403    | `key_read_only`, `key_scope_mismatch`, `ip_not_allowed`     |
| `NotFoundException`            | 404    | `not_found`                                                 |
| `ConflictException`            | 409    | `sequential_already_used`, `ride_not_available`             |
| `UnprocessableEntityException` | 422    | `validation_failed`, `total_mismatch`                       |
| `RateLimitException`           | 429    | `demo_issuer_quota_exceeded`, `account_email_limit_reached` |
| `InternalServerException`      | 5xx    | `internal_error`                                            |
| `ConnectionException`          | —      | no answer (network); `TimeoutException` when it timed out   |

All of them extend `FlashpiException`. Some errors carry extra members in `$error->problem`, such
as `documentId`, `limit`, `resetsAt` or `rideStatus`. For Spanish messages:
`new Flashpi(language: 'es')`.

## Retries and idempotency

The SDK retries network errors, timeouts, and 408, 429 (when asked to wait 60 s or less) and
5xx answers up to 2 times, with growing waits. Every POST carries an `Idempotency-Key` that its
retries repeat, so the API never issues twice:

- When a retry finds that the first attempt did create the document, the SDK returns that
  document.
- When the first attempt is still running on the API (for example, the connection dropped while
  it waited for the SRI), the SDK waits for it with the same key, up to the call's time limit,
  without using up retries.

To use your own key (for example your order id), pass it to the call:

```php
$flashpi->invoices->create($body, ['idempotencyKey' => "order-{$order->id}"]);
```

If issuing still fails, the exception (a `FlashpiException`) carries the key it used in
`$error->idempotencyKey`. Trying again with `['idempotencyKey' => $error->idempotencyKey]` never
issues a second document: within 24 hours the API returns the first answer again; after that (or
if the API lost track of the first attempt), the call fails with a 422 `idempotency_key_reused`
whose `$error->problem['documentId']` is the existing document (read it with
`$flashpi->documents->get()`).

```php
use Flashpi\Exception\FlashpiException;

try {
    $flashpi->invoices->create($body);
} catch (FlashpiException $error) {
    if ($error->idempotencyKey !== null) {
        saveForRetry($body, $error->idempotencyKey); // your retry queue
    }
    throw $error;
}
```

Daily limits (such as the demo issuer's quota) are not retried: they are thrown as a
`RateLimitException` whose `retryAfter()` gives the seconds to wait.

## Webhooks

Verify every webhook with the body **exactly as received** (not parsed) and the endpoint's
`whsec_` secret. `Flashpi::unwrapWebhook()` needs no API key (it is also
`$flashpi->webhooks->unwrap()`) and accepts the headers as your framework hands them over.

In plain PHP:

```php
use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Flashpi;

try {
    $event = Flashpi::unwrapWebhook(
        (string) file_get_contents('php://input'),
        getallheaders(),
        (string) getenv('FLASHPI_WEBHOOK_SECRET'),
    );
} catch (WebhookVerificationException) {
    http_response_code(400);
    exit;
}

if ($event['type'] === 'webhook_endpoint.verification') {
    // The endpoint's activation: answer the challenge.
    header('Content-Type: application/json');
    echo json_encode(['challenge' => $event['data']['challenge']]);
    exit;
}
if ($event['type'] === 'invoice.authorized') {
    error_log('Authorized ' . $event['data']['accessKey']); // $event['data'] is the document
} elseif ($event['type'] === 'invoice.rejected') {
    error_log('Rejected ' . json_encode($event['data']['sriMessages']));
}
http_response_code(204);
```

With Laravel (route it with `Route::post('/webhooks/flashpi', FlashpiWebhookController::class)`
in `routes/api.php`, which has no CSRF check):

```php
use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Flashpi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

final class FlashpiWebhookController
{
    public function __invoke(Request $request): Response|JsonResponse
    {
        try {
            $event = Flashpi::unwrapWebhook(
                $request->getContent(),
                $request->headers,
                Config::string('services.flashpi.webhook_secret'),
            );
        } catch (WebhookVerificationException) {
            return new Response('', 400);
        }

        if ($event['type'] === 'webhook_endpoint.verification') {
            return new JsonResponse(['challenge' => $event['data']['challenge']]);
        }
        handleEvent($event); // your code, like the branches of the plain PHP example

        return new Response('', 204);
    }
}
```

With [flashpi/flashpi-laravel](https://github.com/flashpi1/flashpi-laravel/blob/main/README.en.md#webhooks)
(Laravel 12.71 or later, or 13.40 or later) you do not need this controller: the package's route
verifies the signature, answers the challenge, drops repeated deliveries and dispatches one Laravel
event per type (`InvoiceAuthorized`, ...).

With Symfony:

```php
use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Flashpi;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FlashpiWebhookController
{
    public function __construct(
        #[Autowire(env: 'FLASHPI_WEBHOOK_SECRET')]
        private readonly string $webhookSecret,
    ) {}

    #[Route('/webhooks/flashpi', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $event = Flashpi::unwrapWebhook($request->getContent(), $request->headers, $this->webhookSecret);
        } catch (WebhookVerificationException) {
            return new Response('', 400);
        }

        if ($event['type'] === 'webhook_endpoint.verification') {
            return new JsonResponse(['challenge' => $event['data']['challenge']]);
        }
        handleEvent($event); // your code, like the branches of the plain PHP example

        return new Response('', 204);
    }
}
```

Store `$event['id']` and skip repeats: an event can arrive more than once. During a secret
rotation Flashpi signs with both the new and the previous secret, so either works. If the
endpoint uses `thin` mode (no `data`), type the result as `ThinWebhookEvent`
(`@phpstan-import-type ThinWebhookEvent from \Flashpi\Types\SdkTypes`) and fetch the event with
`$flashpi->events->get($event['id'])`.

## Issuers and certificates

These operations need an account-wide key with read and write access. Once an issuer has live
documents, changing it (its data, establishments, emission points or signature) also needs an
`fp_live_` key: with an `fp_test_` one, the API answers 403 `live_key_required`.

An issuer starts from its electronic signature (.p12): Flashpi checks it, reads the RUC and asks
the SRI, and returns a `prefill` with what it found so you can build the issuer.

```php
$onboarding = $flashpi->issuerOnboardings->create([
    'file' => (string) file_get_contents('firma.p12'),
    'password' => (string) getenv('P12_PASSWORD'),
]);
while ($onboarding['status'] === 'verifying') {
    sleep(2);
    $onboarding = $flashpi->issuerOnboardings->get($onboarding['id']);
}
if ($onboarding['status'] !== 'ready') {
    throw new \RuntimeException($onboarding['rejection']['message'] ?? $onboarding['status']);
}

$prefill = $onboarding['prefill'];
if ($onboarding['existingIssuer'] !== null) {
    // You already have that RUC: the certificate is added to that issuer (a renewal).
    $completed = $flashpi->issuerOnboardings->complete($onboarding['id']);
} else {
    // A new RUC: you confirm the issuer's data, starting from the prefill.
    $taxProfile = $prefill['taxProfile'] ?? null;
    $rimpeRegime = $taxProfile['rimpeRegime'] ?? null;
    $completed = $flashpi->issuerOnboardings->complete($onboarding['id'], [
        'legalName' => $prefill['legalName'] ?? 'MY COMPANY S.A.',
        'headOfficeAddress' => $prefill['headOfficeAddress'] ?? 'Av. Amazonas N24-100, Quito',
        'taxProfile' => [
            'keepsAccounting' => $taxProfile['keepsAccounting'] ?? false,
            'rimpeRegime' => $rimpeRegime === 'general' ? null : $rimpeRegime,
            // For a special taxpayer or a withholding agent, add their resolution numbers.
        ],
        // When $onboarding['rucNeedsConfirmation'] is true, send 'ruc' too.
    ]);
}

foreach ($flashpi->issuers->establishments->list($completed['issuer']['id']) as $establishment) {
    echo $establishment['code'], "\n";
}
```

The `file` can be the contents of the .p12 as a string, a stream opened for reading
(`fopen('firma.p12', 'rb')`) or a PSR-7 stream, up to 1 MiB.

To **renew an issuer's signature** for test and live mode at once, run the same flow with the
new .p12: Flashpi recognizes the RUC (`existingIssuer`) and `complete()` with no data adds the
certificate. Instead,
`$flashpi->issuers->certificates->upload($issuerId, ['file' => ..., 'password' => ...])` uploads
a certificate for the mode of the key you use only (test or live).

## Options

```php
use Flashpi\Flashpi;

$flashpi = new Flashpi(
    apiKey: 'fp_test_...', // omit it to read FLASHPI_API_KEY ($_ENV, $_SERVER or getenv())
    language: 'es', // language of the messages: 'en' (default) or 'es'
    timeout: 60, // seconds per attempt (at least the SRI wait + 15 s with waitForAuthorization)
    maxRetries: 2, // 0 turns retries off
);
```

Every method also takes, as its last argument, an array with the options of that request:
`idempotencyKey`, `timeout`, `maxRetries`, `language`, `correlationId` (your own id, which
Flashpi logs) and `extraHeaders`. An unknown option is refused. `extraHeaders`, like the
client's `defaultHeaders`, cannot carry `Content-Length`, `Transfer-Encoding`, `Keep-Alive`,
`Upgrade` or `Expect`, which the HTTP client sets or manages: they throw an
`\InvalidArgumentException` before anything is sent.

```php
$document = $flashpi->documents->get($documentId, ['timeout' => 10, 'language' => 'es', 'correlationId' => 'order-42']);
```

To see the HTTP response and the request id, call the method through `withResponse()`:

```php
$result = $flashpi->documents->withResponse()->get($documentId);
echo $result->data['status'], ' ', $result->response->getStatusCode(), ' ', $result->requestId, "\n";
```

To send the requests through your own Guzzle client, for a proxy, middleware or tests, pass it
as `httpClient`. The SDK never follows redirects and sets each attempt's timeout on it.

```php
use Flashpi\Flashpi;
use GuzzleHttp\Client;

$flashpi = new Flashpi(httpClient: new Client(['proxy' => 'http://proxy.internal:3128']));
```

In tests, a client built on Guzzle's `MockHandler` answers in place of the API. Without the curl
extension, Guzzle's stream handler can let headers that arrive slowly keep an attempt going past
its timeout (the answer is then refused), and Guzzle 7's may not notice an answer cut short;
install `ext-curl` in production.

## Servers only

Your API key is a secret: use the SDK on your servers, never in code that runs on your users'
devices. `var_dump($flashpi)` shows the environment and the URL, never the key, and neither do
exceptions or their stack traces.

## License

[MIT](https://github.com/flashpi1/flashpi-php/blob/main/LICENSE) © 2026 SIGNIADIGITAL S.A.
