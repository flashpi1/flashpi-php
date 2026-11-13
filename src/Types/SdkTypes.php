<?php

declare(strict_types=1);

namespace Flashpi\Types;

/**
 * Types the SDK adds to the API's (ApiTypes): shorter names, the webhook bodies, the open error
 * codes, the request options, list filters, uploads and the XML of a document.
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
 * @phpstan-type WebhookHeaders iterable<string, string|iterable<string|null>|null>
 * @phpstan-type DocumentListParams DocumentsListQuery
 * @phpstan-type EventListParams EventsListQuery
 * @phpstan-type WebhookDeliveryListParams WebhookEndpointsDeliveriesListQuery
 * @phpstan-type IssuerListParams IssuersListQuery
 * @phpstan-type EstablishmentListParams EstablishmentsListQuery
 * @phpstan-type EmissionPointListParams EmissionPointsListQuery
 * @phpstan-type CertificateListParams CertificatesListQuery
 * @phpstan-type WebhookEndpointListParams WebhookEndpointsListQuery
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
