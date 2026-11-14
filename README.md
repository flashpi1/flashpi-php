# Flashpi para PHP

SDK oficial de [Flashpi](https://flashpi.com.ec), la API de facturación electrónica del SRI de
Ecuador, para PHP.

[English](https://github.com/flashpi1/flashpi-php/blob/main/README.en.md)

- Las 45 operaciones de la API: los seis comprobantes, documentos (XML, RIDE en PDF, correo),
  emisores con establecimientos, puntos de emisión y certificados .p12, webhooks y eventos.
- Peticiones y respuestas son arrays con las claves de la API, tipados con formas de arrays de
  PHPStan generadas del documento OpenAPI de la API: tu editor y PHPStan conocen cada campo.
- Reintentos seguros: cada emisión lleva un `Idempotency-Key`, así un reintento nunca emite dos
  veces.
- Errores tipados (RFC 9457) con su `errorCode`, los errores por campo y el `requestId`.
- Paginación automática con `foreach`.
- Verificación de firmas de webhooks (Standard Webhooks) para Laravel, Symfony o PHP sin
  framework.
- PHP 8.2 o superior. Una sola dependencia: [Guzzle](https://docs.guzzlephp.org/) 7.8.2 o
  superior, u 8 (Laravel 11, 12 y 13).

## Instalación

```bash
composer require flashpi/flashpi-php
```

## Primeros pasos

Crea una clave de API en la [consola](https://flashpi.com.ec/app/) y guárdala en la variable de
entorno `FLASHPI_API_KEY`. Una clave `fp_test_` emite en el ambiente de pruebas del SRI; una
`fp_live_` emite comprobantes reales en producción.

```php
use Flashpi\Flashpi;

// Lee FLASHPI_API_KEY; también puedes pasarla: new Flashpi(apiKey: 'fp_test_...')
$flashpi = new Flashpi();

$invoice = $flashpi->invoices->create([
    'issuerId' => '<id del emisor demo o del tuyo>',
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
            'description' => 'Servicio de prueba',
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

Las peticiones y las respuestas son arrays asociativos con las claves de la API, en camelCase
(`$invoice['accessKey']`), tipados con las formas de arrays de PHPStan de
`Flashpi\Types\ApiTypes` (`@phpstan-import-type CreateInvoice from \Flashpi\Types\ApiTypes`). Los
montos y cantidades son **textos** (`'1.15'`), nunca números, para no perder decimales.

Con el **emisor demo** puedes emitir en pruebas sin subir un certificado: su id está en el
Resumen de la consola (hasta 50 comprobantes al día). Más en
[Sandbox y emisor demo](https://flashpi.com.ec/docs/sandbox/).

Crea un cliente y reutilízalo (en Laravel, regístralo como singleton en un service provider).

## Análisis estático

Los tipos son formas de arrays de PHPStan. Activa el bleeding edge de PHPStan en tu
`phpstan.neon` para que las formas sean cerradas: sin él, una clave opcional mal escrita
(`'expectedTotl'`) no se reporta.

```neon
includes:
    - vendor/phpstan/phpstan/conf/bleedingEdge.neon
```

Con Guzzle 7, PHPStan lee `getallheaders()` como `mixed` (el polyfill que instala Guzzle no
declara su tipo de retorno): ahí pasa `(array) getallheaders()` a los métodos de webhooks.

## Emitir comprobantes

| Comprobante           | Método                                         |
| --------------------- | ---------------------------------------------- |
| Factura               | `$flashpi->invoices->create($body)`            |
| Nota de crédito       | `$flashpi->creditNotes->create($body)`         |
| Nota de débito        | `$flashpi->debitNotes->create($body)`          |
| Liquidación de compra | `$flashpi->purchaseSettlements->create($body)` |
| Guía de remisión      | `$flashpi->waybills->create($body)`            |
| Retención             | `$flashpi->withholdings->create($body)`        |

Todos devuelven el documento. Por defecto la respuesta llega enseguida con el estado
`processing` y el resultado del SRI llega por webhook. Con
`'options' => ['waitForAuthorization' => true]` la API espera la respuesta del SRI (15 s por
defecto, hasta 30 s con `waitTimeoutSeconds`) y devuelve `authorized`, `rejected` o `failed`; si
el SRI tarda más, devuelve `processing` o `pending_sri` y el documento sigue su curso.

## Documentos

```php
$document = $flashpi->documents->get($invoice['id']);

$xml = $flashpi->documents->xml($invoice['id']); // $xml['kind']: 'authorized' cuando ya lo autorizó el SRI
$pdf = $flashpi->documents->pdf($invoice['id']); // el RIDE, en bytes
file_put_contents("{$invoice['accessKey']}.pdf", $pdf);

$flashpi->documents->sendEmail($invoice['id']); // reenvía RIDE y XML al correo del comprador
```

## Listas y paginación

Una lista devuelve una página. Con `foreach` recorres todas las páginas, que se piden a medida
que avanzas:

```php
foreach ($flashpi->documents->list(['status' => 'authorized', 'issueDateFrom' => '2026-11-01']) as $document) {
    echo $document['accessKey'], "\n";
}

$page = $flashpi->documents->list(['limit' => 50]);
echo count($page->data), "\n"; // los documentos de esta página
if ($page->hasNextPage()) {
    $page = $page->nextPage();
}
```

Los filtros usan los nombres de la API (`issueDateFrom`, `issuerId`).

## Errores

Cada error de la API es una subclase de `ApiException` según su estado HTTP. Decide según
`errorCode`, que es estable y en inglés; el mensaje sigue el idioma pedido.

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
    echo $error->status, ' ', $error->errorCode, ' ', $error->requestId, "\n"; // comparte requestId con soporte
    throw $error;
}
```

| Clase (`Flashpi\Exception`)    | Estado | Ejemplos de `errorCode`                                      |
| ------------------------------ | ------ | ------------------------------------------------------------ |
| `BadRequestException`          | 400    | `malformed_json`, `invalid_idempotency_key`                  |
| `AuthenticationException`      | 401    | `authentication_required`, `invalid_api_key`                 |
| `PermissionDeniedException`    | 403    | `key_read_only`, `key_scope_mismatch`, `ip_not_allowed`      |
| `NotFoundException`            | 404    | `not_found`                                                  |
| `ConflictException`            | 409    | `sequential_already_used`, `ride_not_available`              |
| `UnprocessableEntityException` | 422    | `validation_failed`, `total_mismatch`                        |
| `RateLimitException`           | 429    | `demo_issuer_quota_exceeded`, `account_email_limit_reached`  |
| `InternalServerException`      | 5xx    | `internal_error`                                             |
| `ConnectionException`          | —      | sin respuesta (red); `TimeoutException` si se agotó el plazo |

Todas derivan de `FlashpiException`. Algunos errores traen datos extra en `$error->problem`, por
ejemplo `documentId`, `limit`, `resetsAt` o `rideStatus`. Para recibir los mensajes en español:
`new Flashpi(language: 'es')`.

## Reintentos e idempotencia

El SDK reintenta hasta 2 veces, con espera creciente, los errores de red, los tiempos agotados,
las respuestas 408, 429 (si piden esperar 60 s o menos) y 5xx. Cada POST lleva un
`Idempotency-Key` que se repite en sus reintentos, así que la API nunca emite dos veces:

- Si un reintento descubre que el primer intento sí creó el documento, el SDK devuelve ese
  documento.
- Si el primer intento sigue en curso en la API (por ejemplo, se cortó la conexión mientras
  esperaba al SRI), el SDK espera a que termine con la misma clave, hasta el tiempo límite de la
  llamada, sin gastar reintentos.

Para usar tu propia clave (por ejemplo el id de tu pedido), pásala a la llamada:

```php
$flashpi->invoices->create($body, ['idempotencyKey' => "pedido-{$order->id}"]);
```

Si una emisión falla igual, la excepción (una `FlashpiException`) trae la clave que usó en
`$error->idempotencyKey`. Reintentar con `['idempotencyKey' => $error->idempotencyKey]` nunca
emite un segundo documento: dentro de 24 horas la API devuelve la primera respuesta; después (o
si la API perdió el primer intento), la llamada falla con un 422 `idempotency_key_reused` cuyo
`$error->problem['documentId']` es el documento que ya existe (léelo con
`$flashpi->documents->get()`).

```php
use Flashpi\Exception\FlashpiException;

try {
    $flashpi->invoices->create($body);
} catch (FlashpiException $error) {
    if ($error->idempotencyKey !== null) {
        saveForRetry($body, $error->idempotencyKey); // tu cola de reintentos
    }
    throw $error;
}
```

Los límites diarios (como la cuota del emisor demo) no se reintentan: se lanzan como
`RateLimitException`, cuyo `retryAfter()` da los segundos de espera.

## Webhooks

Verifica cada webhook con el cuerpo **tal cual llegó** (sin parsear) y el secreto `whsec_` del
endpoint. `Flashpi::unwrapWebhook()` no necesita clave de API (también está en
`$flashpi->webhooks->unwrap()`) y acepta los encabezados como te los entrega tu framework.

En PHP sin framework:

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
    // La activación del endpoint: responde el desafío.
    header('Content-Type: application/json');
    echo json_encode(['challenge' => $event['data']['challenge']]);
    exit;
}
if ($event['type'] === 'invoice.authorized') {
    error_log('Autorizada ' . $event['data']['accessKey']); // $event['data'] es el documento
} elseif ($event['type'] === 'invoice.rejected') {
    error_log('Rechazada ' . json_encode($event['data']['sriMessages']));
}
http_response_code(204);
```

Con Laravel (regístralo con `Route::post('/webhooks/flashpi', FlashpiWebhookController::class)`
en `routes/api.php`, que no verifica CSRF):

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
        handleEvent($event); // tu código, como las ramas del ejemplo sin framework

        return new Response('', 204);
    }
}
```

Con Symfony:

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
        handleEvent($event); // tu código, como las ramas del ejemplo sin framework

        return new Response('', 204);
    }
}
```

Guarda el `$event['id']` y descarta los repetidos: un evento puede llegar más de una vez.
Durante una rotación del secreto, Flashpi firma con el nuevo y el anterior, así que cualquiera de
los dos sirve. Si el endpoint usa el modo `thin` (sin `data`), tipa el resultado como
`ThinWebhookEvent` (`@phpstan-import-type ThinWebhookEvent from \Flashpi\Types\SdkTypes`) y pide
el evento con `$flashpi->events->get($event['id'])`.

## Emisores y certificados

Estas operaciones necesitan una clave de toda la cuenta con acceso de lectura y escritura.

Un emisor nace de su firma electrónica (.p12): Flashpi la verifica, lee el RUC y consulta al SRI,
y te devuelve un `prefill` con lo que encontró para que armes el emisor.

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
    // Ya tienes ese RUC: el certificado se agrega a ese emisor (una renovación).
    $completed = $flashpi->issuerOnboardings->complete($onboarding['id']);
} else {
    // Un RUC nuevo: tú confirmas los datos del emisor, partiendo del prefill.
    $taxProfile = $prefill['taxProfile'] ?? null;
    $rimpeRegime = $taxProfile['rimpeRegime'] ?? null;
    $completed = $flashpi->issuerOnboardings->complete($onboarding['id'], [
        'legalName' => $prefill['legalName'] ?? 'MI EMPRESA S.A.',
        'headOfficeAddress' => $prefill['headOfficeAddress'] ?? 'Av. Amazonas N24-100, Quito',
        'taxProfile' => [
            'keepsAccounting' => $taxProfile['keepsAccounting'] ?? false,
            'rimpeRegime' => $rimpeRegime === 'general' ? null : $rimpeRegime,
            // Si es contribuyente especial o agente de retención, agrega sus resoluciones.
        ],
        // Si $onboarding['rucNeedsConfirmation'] es true, envía también 'ruc'.
    ]);
}

foreach ($flashpi->issuers->establishments->list($completed['issuer']['id']) as $establishment) {
    echo $establishment['code'], "\n";
}
```

El `file` puede ser el contenido del .p12 como texto, un stream abierto para lectura
(`fopen('firma.p12', 'rb')`) o un stream PSR-7, de hasta 1 MiB.

Para **renovar la firma** de un emisor, para pruebas y producción a la vez, repite el mismo
flujo con el .p12 nuevo: Flashpi reconoce el RUC (`existingIssuer`) y `complete()` sin datos
agrega el certificado. En cambio,
`$flashpi->issuers->certificates->upload($issuerId, ['file' => ..., 'password' => ...])` sube un
certificado solo para el modo de la clave que usas (pruebas o producción).

## Opciones

```php
use Flashpi\Flashpi;

$flashpi = new Flashpi(
    apiKey: (string) getenv('FLASHPI_API_KEY'), // por defecto, la variable FLASHPI_API_KEY
    language: 'es', // idioma de los mensajes: 'en' (por defecto) o 'es'
    timeout: 60, // segundos por intento (con waitForAuthorization, al menos la espera + 15 s)
    maxRetries: 2, // 0 desactiva los reintentos
);
```

Cada método acepta además, como último argumento, un array con opciones de esa petición:
`idempotencyKey`, `timeout`, `maxRetries`, `language`, `correlationId` (tu propio id, que
Flashpi registra) y `extraHeaders`. Una opción desconocida se rechaza.

```php
$document = $flashpi->documents->get($documentId, ['timeout' => 10, 'language' => 'es', 'correlationId' => 'pedido-42']);
```

Para ver la respuesta HTTP y el id de la petición, llama al método a través de `withResponse()`:

```php
$result = $flashpi->documents->withResponse()->get($documentId);
echo $result->data['status'], ' ', $result->response->getStatusCode(), ' ', $result->requestId, "\n";
```

Para enviar las peticiones con tu propio cliente de Guzzle, por ejemplo con un proxy, middleware
o en pruebas, pásalo en `httpClient`. El SDK nunca sigue redirecciones y fija en él el tiempo
límite de cada intento.

```php
use Flashpi\Flashpi;
use GuzzleHttp\Client;

$flashpi = new Flashpi(httpClient: new Client(['proxy' => 'http://proxy.interno:3128']));
```

En las pruebas, un cliente con el `MockHandler` de Guzzle responde en lugar de la API. Sin la
extensión curl, el stream handler de Guzzle puede dejar que encabezados que llegan lentamente
alarguen un intento más allá de su tiempo límite (la respuesta se rechaza después); instala
`ext-curl` en producción.

## Solo en el servidor

Tu clave de API es secreta: usa el SDK en tus servidores, nunca en código que corre en los
dispositivos de tus usuarios. `var_dump($flashpi)` muestra el ambiente y la URL, nunca la clave,
y tampoco la muestran las excepciones ni sus trazas.

## Licencia

[MIT](https://github.com/flashpi1/flashpi-php/blob/main/LICENSE) © 2026 SIGNIADIGITAL S.A.
