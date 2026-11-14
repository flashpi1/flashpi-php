<?php

declare(strict_types=1);

namespace Flashpi\Types;

use Psr\Http\Message\StreamInterface;

/**
 * Types the SDK adds to the API's (ApiTypes): shorter names, the webhook bodies, the open error
 * codes, the request options, list filters, uploads and the XML of a document.
 *
 * - `CertificateUpload`: a .p12 certificate and its password, for
 *   `$flashpi->issuers->certificates->upload()` and `$flashpi->issuerOnboardings->create()`.
 *   `file` is the .p12 (PKCS #12), up to 1 MiB: its contents as a string
 *   (`file_get_contents('firma.p12')`), a stream opened for reading (`fopen('firma.p12', 'rb')`)
 *   or a PSR-7 stream, read once from where it stands. `password` is sent to Flashpi only, never
 *   stored in plain text. `filename` is the name sent with it (default `certificate.p12`).
 * - `WebhookHeaders`: the headers of a webhook request, any iterable of header names to values
 *   or lists of values, as servers hand them over: an array, PSR-7's `getHeaders()`,
 *   `getallheaders()`, Symfony's `HeaderBag`, a generator. Entries that are not strings are
 *   skipped.
 * - `DocumentXml`: the XML of a document (`xml`) and which stage it comes from (`kind`):
 *   `authorized` (the SRI's authorization with the document inside, the valid tax document),
 *   `signed` (sent, not yet authorized) or `unsigned` (before signing); null when the API did not
 *   say.
 *
 * Import one in your own PHPDoc with `@phpstan-import-type WebhookEvent from \Flashpi\Types\SdkTypes`.
 *
 * @phpstan-import-type AccountEventType from ApiTypes
 * @phpstan-import-type CertificateEventType from ApiTypes
 * @phpstan-import-type CertificatesListQuery from ApiTypes
 * @phpstan-import-type DocumentEnvironment from ApiTypes
 * @phpstan-import-type DocumentEventType from ApiTypes
 * @phpstan-import-type DocumentsListQuery from ApiTypes
 * @phpstan-import-type EmissionPointsListQuery from ApiTypes
 * @phpstan-import-type ErrorCode from ApiTypes
 * @phpstan-import-type EstablishmentsListQuery from ApiTypes
 * @phpstan-import-type Event from ApiTypes
 * @phpstan-import-type EventsListQuery from ApiTypes
 * @phpstan-import-type EventThin from ApiTypes
 * @phpstan-import-type FieldErrorCode from ApiTypes
 * @phpstan-import-type IssuersListQuery from ApiTypes
 * @phpstan-import-type ProblemDetails from ApiTypes
 * @phpstan-import-type WebhookEndpointsDeliveriesListQuery from ApiTypes
 * @phpstan-import-type WebhookEndpointsListQuery from ApiTypes
 * @phpstan-import-type WebhookEndpointVerificationEvent from ApiTypes
 *
 * @phpstan-type Language 'en'|'es'
 * @phpstan-type Environment DocumentEnvironment
 * @phpstan-type RequestOptions array{
 *     idempotencyKey?: string|null,
 *     timeout?: int|float|null,
 *     maxRetries?: int|null,
 *     language?: Language|null,
 *     correlationId?: string|null,
 *     extraHeaders?: array<string, string>|null,
 * }
 * @phpstan-type ApiErrorCode ErrorCode|string
 * @phpstan-type ApiFieldErrorCode FieldErrorCode|string
 * @phpstan-type Problem ProblemDetails
 * @phpstan-type EventType DocumentEventType|CertificateEventType|AccountEventType
 * @phpstan-type ThinEvent EventThin
 * @phpstan-type WebhookEvent Event|WebhookEndpointVerificationEvent
 * @phpstan-type ThinWebhookEvent EventThin|WebhookEndpointVerificationEvent
 * @phpstan-type WebhookHeaders iterable<mixed, mixed>
 * @phpstan-type DocumentListParams DocumentsListQuery
 * @phpstan-type EventListParams EventsListQuery
 * @phpstan-type WebhookDeliveryListParams WebhookEndpointsDeliveriesListQuery
 * @phpstan-type IssuerListParams IssuersListQuery
 * @phpstan-type EstablishmentListParams EstablishmentsListQuery
 * @phpstan-type EmissionPointListParams EmissionPointsListQuery
 * @phpstan-type CertificateListParams CertificatesListQuery
 * @phpstan-type WebhookEndpointListParams WebhookEndpointsListQuery
 * @phpstan-type CertificateUpload array{
 *     file: string|resource|StreamInterface,
 *     password: string,
 *     filename?: string,
 * }
 * @phpstan-type DocumentXmlKind 'authorized'|'signed'|'unsigned'
 * @phpstan-type DocumentXml array{
 *     xml: string,
 *     kind: DocumentXmlKind|null,
 * }
 */
final class SdkTypes
{
    private function __construct() {}
}
