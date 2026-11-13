<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * One API operation, as a resource declares it.
 *
 * ```php
 * new RequestSpec('GET', Path::of('/v1/documents/{}', $documentId), options: $options);
 * new RequestSpec('GET', '/v1/documents', query: $params, options: $options);
 * RequestSpec::issueDocument('/v1/invoices', $body, $options);
 * new RequestSpec('GET', $path, accept: 'application/pdf, application/problem+json', responseType: 'bytes', options: $options);
 * ```
 *
 * @internal
 */
final class RequestSpec
{
    /** Seconds the API waits for the SRI in sync mode when `waitTimeoutSeconds` is not sent. */
    public const DEFAULT_WAIT_SECONDS = 15.0;

    /** Time on top of the sync wait for Flashpi to build, sign and answer. */
    public const SYNC_MARGIN_SECONDS = 15.0;

    /**
     * @param 'GET'|'POST'|'PATCH'|'DELETE' $method
     * @param string $path The path, built with Path::of() when it carries ids.
     * @param array<string, mixed>|null $query Query parameters; null values are left out, booleans
     *                                         are sent as true and false.
     * @param mixed $body A JSON body (an array or a JsonSerializable), serialized once for every
     *                    attempt; an empty array is sent as `{}`. Null sends no body.
     * @param Multipart|null $multipart A multipart body, instead of `body`.
     * @param string $accept The Accept header.
     * @param 'json'|'text'|'bytes'|'none' $responseType How to read a successful answer: decoded
     *                                                   JSON, the body as a string (text or raw
     *                                                   bytes), or nothing (null).
     * @param array<mixed> $options The caller's options (RequestOptions), checked by the engine.
     * @param float $minTimeout A floor, in seconds, for the per-attempt timeout of requests the
     *                          API holds open (issuing with `options.waitForAuthorization`).
     * @param bool $recoverDocument For document POSTs: when a retry of this call is answered 422
     *                              `idempotency_key_reused` with a `documentId`, an earlier attempt
     *                              created the document, so the call returns
     *                              GET /v1/documents/{documentId} instead of failing.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?array $query = null,
        public readonly mixed $body = null,
        public readonly ?Multipart $multipart = null,
        public readonly string $accept = 'application/json',
        public readonly string $responseType = 'json',
        public readonly array $options = [],
        public readonly float $minTimeout = 0.0,
        public readonly bool $recoverDocument = false,
    ) {}

    /**
     * The POST that issues a document.
     *
     * Its retries reuse the same Idempotency-Key, and when a retry finds the document already
     * created by an earlier attempt (422 `idempotency_key_reused` with a `documentId`), the call
     * returns that document instead of an error. In sync mode its attempts wait at least as long
     * as the API holds the request (syncTimeoutFloor()).
     *
     * @param array<mixed> $options
     */
    public static function issueDocument(string $path, mixed $body, array $options): self
    {
        return new self('POST', $path, body: $body, options: $options, minTimeout: self::syncTimeoutFloor($body), recoverDocument: true);
    }

    /**
     * The shortest per-attempt timeout for issuing `$body`, in seconds.
     *
     * In sync mode (`options.waitForAuthorization`) the API holds the request for up to
     * `waitTimeoutSeconds` (15 by default) while the SRI decides, so each attempt waits at least
     * that plus 15 seconds. Otherwise 0: the client's timeout applies.
     */
    public static function syncTimeoutFloor(mixed $body): float
    {
        $settings = \is_array($body) ? $body['options'] ?? null : null;
        if (!\is_array($settings) || ($settings['waitForAuthorization'] ?? null) !== true) {
            return 0.0;
        }
        $wait = $settings['waitTimeoutSeconds'] ?? null;

        return (\is_int($wait) || \is_float($wait) ? (float) $wait : self::DEFAULT_WAIT_SECONDS) + self::SYNC_MARGIN_SECONDS;
    }

    /**
     * The same request for the page after `cursor` (same filters and options).
     */
    public function withCursor(string $cursor): self
    {
        return new self(
            $this->method,
            $this->path,
            [...$this->query ?? [], 'cursor' => $cursor],
            $this->body,
            $this->multipart,
            $this->accept,
            $this->responseType,
            $this->options,
            $this->minTimeout,
            $this->recoverDocument,
        );
    }
}
