<?php

declare(strict_types=1);

namespace Flashpi;

use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Types\SdkTypes;

/**
 * Verification of the webhooks Flashpi sends (Standard Webhooks), available as
 * `$flashpi->webhooks`, `new Webhooks()` or `Flashpi::unwrapWebhook()`. It needs no API key.
 *
 * A webhook is signed with HMAC-SHA256 over `webhook-id.webhook-timestamp.body` using the
 * endpoint's `whsec_` secret. During a secret rotation Flashpi signs with both secrets, so the
 * `webhook-signature` header lists several signatures.
 *
 * ```php
 * $event = $flashpi->webhooks->unwrap($rawBody, getallheaders(), $_ENV['FLASHPI_WEBHOOK_SECRET']);
 * if ($event['type'] === 'webhook_endpoint.verification') {
 *     echo json_encode(['challenge' => $event['data']['challenge']]);
 * }
 * ```
 *
 * @phpstan-import-type WebhookEvent from SdkTypes
 * @phpstan-import-type WebhookHeaders from SdkTypes
 */
final class Webhooks
{
    /** Default largest gap, in seconds, between a webhook's timestamp and the receiver's clock. */
    public const TOLERANCE_SECONDS = 300;

    private const SECRET_PREFIX = 'whsec_';

    /** Key sizes of a Standard Webhooks secret (Flashpi makes 32-byte keys). */
    private const MIN_SECRET_BYTES = 24;

    private const MAX_SECRET_BYTES = 64;

    private const SIGNATURE_BYTES = 32;

    /**
     * Checks that a webhook comes from Flashpi and is recent; throws a
     * WebhookVerificationException otherwise: answer 400 and ignore the request.
     *
     * Pass the raw body exactly as received (`file_get_contents('php://input')`, a PSR-7 body);
     * a body decoded and encoded again does not match.
     *
     * @param string|\Stringable $payload The raw body.
     * @param WebhookHeaders $headers The request headers, names in any letter case; a header may
     *                                come as a list of values (PSR-7, Symfony).
     * @param string $secret The endpoint's signing secret (`whsec_...`).
     * @param int|float|null $tolerance Largest gap, in seconds, between `webhook-timestamp` and
     *                                  now (default 300).
     * @param \DateTimeInterface|int|float|null $now The current time, for tests: a date or Unix
     *                                               seconds (default: the clock).
     *
     * @throws WebhookVerificationException
     * @throws \InvalidArgumentException A tolerance or a time that is not finite, or a negative
     *                                   tolerance.
     */
    public function verifySignature(
        mixed $payload,
        iterable $headers,
        #[\SensitiveParameter]
        mixed $secret,
        int|float|null $tolerance = null,
        \DateTimeInterface|int|float|null $now = null,
    ): void {
        [$toleranceSeconds, $nowSeconds] = self::times($tolerance, $now);
        $key = self::key($secret);
        // Read once: the headers may come from a generator.
        $values = self::values($headers);
        $webhookId = $values['webhook-id'][0] ?? null;
        $timestamp = $values['webhook-timestamp'][0] ?? null;
        $signatures = isset($values['webhook-signature']) ? implode(' ', $values['webhook-signature']) : null;
        if ($webhookId === null || $webhookId === '' || $timestamp === null || $timestamp === '' || $signatures === null || $signatures === '') {
            throw new WebhookVerificationException(
                WebhookVerificationException::MISSING_HEADERS,
                'The webhook-id, webhook-timestamp and webhook-signature headers are required',
            );
        }
        if (preg_match('/^[0-9]{1,12}$/', $timestamp) !== 1) {
            throw new WebhookVerificationException(WebhookVerificationException::INVALID_TIMESTAMP, 'The webhook-timestamp is not valid');
        }
        // Written so that anything unexpected fails closed.
        if (!(abs(floor($nowSeconds) - (int) $timestamp) <= $toleranceSeconds)) {
            throw new WebhookVerificationException(
                WebhookVerificationException::TIMESTAMP_OUT_OF_RANGE,
                "The webhook-timestamp is more than {$toleranceSeconds} seconds away from now",
            );
        }
        $body = self::payload($payload);
        if (!self::signed($key, "{$webhookId}.{$timestamp}.{$body}", $signatures)) {
            throw new WebhookVerificationException(
                WebhookVerificationException::NO_MATCHING_SIGNATURE,
                'No webhook-signature matches the body and the secret',
            );
        }
    }

    /**
     * Verifies a webhook (see verifySignature()) and returns its decoded body.
     *
     * Narrow the event on `$event['type']`. Endpoints in thin mode receive no `data`: type the
     * result as ThinWebhookEvent there.
     *
     * @param string|\Stringable $payload
     * @param WebhookHeaders $headers
     * @param string $secret
     *
     * @return WebhookEvent
     *
     * @throws WebhookVerificationException
     * @throws \InvalidArgumentException
     */
    public function unwrap(
        mixed $payload,
        iterable $headers,
        #[\SensitiveParameter]
        mixed $secret,
        int|float|null $tolerance = null,
        \DateTimeInterface|int|float|null $now = null,
    ): array {
        $this->verifySignature($payload, $headers, $secret, $tolerance, $now);
        $body = self::payload($payload);
        try {
            $event = json_decode($body, true, 512, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            throw new WebhookVerificationException(WebhookVerificationException::INVALID_PAYLOAD, 'The webhook body is not JSON');
        }
        // An empty object decodes to [] like an empty list: the first character tells them apart.
        if (!\is_array($event) || !str_starts_with(ltrim($body, " \t\r\n"), '{')) {
            throw new WebhookVerificationException(WebhookVerificationException::INVALID_PAYLOAD, 'The webhook body is not a JSON object');
        }
        /** @var WebhookEvent $event */
        return $event;
    }

    /**
     * The checked tolerance and clock. Values that are not finite are programming errors, never a
     * skipped time check.
     *
     * @return array{float, float}
     */
    private static function times(int|float|null $tolerance, \DateTimeInterface|int|float|null $now): array
    {
        $tolerance ??= self::TOLERANCE_SECONDS;
        if (!is_finite((float) $tolerance) || $tolerance < 0) {
            throw new \InvalidArgumentException('tolerance must be a number of seconds, 0 or more');
        }
        $seconds = match (true) {
            $now === null => microtime(true),
            $now instanceof \DateTimeInterface => (float) $now->format('U.u'),
            default => (float) $now,
        };
        if (!is_finite($seconds)) {
            throw new \InvalidArgumentException('now must be a date or a number of Unix seconds');
        }

        return [(float) $tolerance, $seconds];
    }

    /**
     * The decoded signing key of a secret.
     */
    private static function key(#[\SensitiveParameter] mixed $secret): string
    {
        if (!\is_string($secret) || trim($secret) === '') {
            throw new WebhookVerificationException(
                WebhookVerificationException::INVALID_SECRET,
                'The webhook secret is empty: copy it from the console or webhookEndpoints->getSecret()',
            );
        }
        $encoded = trim($secret);
        if (str_starts_with($encoded, self::SECRET_PREFIX)) {
            $encoded = substr($encoded, \strlen(self::SECRET_PREFIX));
        }
        $key = self::base64($encoded) ?? '';
        $size = \strlen($key);
        if ($size >= self::MIN_SECRET_BYTES && $size <= self::MAX_SECRET_BYTES) {
            return $key;
        }
        if ($size === 0) {
            throw new WebhookVerificationException(
                WebhookVerificationException::INVALID_SECRET,
                'The webhook secret is not a whsec_ secret (base64 after the prefix)',
            );
        }

        throw new WebhookVerificationException(
            WebhookVerificationException::INVALID_SECRET,
            'The webhook secret is not a complete whsec_ secret: copy it again from the console or webhookEndpoints->getSecret()',
        );
    }

    /**
     * Whether a v1 signature of the header signs `$message`, compared in constant time. One HMAC
     * per request, however many signatures the header lists.
     */
    private static function signed(#[\SensitiveParameter] string $key, string $message, string $signatures): bool
    {
        $expected = hash_hmac('sha256', $message, $key, true);
        foreach (explode(' ', $signatures) as $entry) {
            $comma = strpos($entry, ',');
            if ($comma === false || substr($entry, 0, $comma) !== 'v1') {
                continue;
            }
            $signature = self::base64(substr($entry, $comma + 1));
            if ($signature !== null && \strlen($signature) === self::SIGNATURE_BYTES && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The text values of the three webhook headers, by lowercase name, in the order they came. A
     * repeated header keeps every value: the signature lists are joined, otherwise the first value
     * counts.
     *
     * @param iterable<mixed, mixed> $headers
     *
     * @return array<string, non-empty-list<string>>
     */
    private static function values(iterable $headers): array
    {
        $values = [];
        foreach ($headers as $key => $value) {
            $name = \is_string($key) ? strtolower($key) : '';
            if (!\in_array($name, ['webhook-id', 'webhook-timestamp', 'webhook-signature'], true)) {
                continue;
            }
            foreach (is_iterable($value) ? $value : [$value] as $item) {
                if (\is_string($item)) {
                    $values[$name][] = $item;
                }
            }
        }

        return $values;
    }

    private static function payload(mixed $payload): string
    {
        if (\is_string($payload)) {
            return $payload;
        }
        if ($payload instanceof \Stringable) {
            return (string) $payload;
        }

        throw new WebhookVerificationException(
            WebhookVerificationException::INVALID_PAYLOAD,
            'Pass the raw webhook body as a string (not decoded JSON)',
        );
    }

    /**
     * Standard base64 to bytes, or null when it is not base64 (padding optional, like atob).
     */
    private static function base64(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $value) !== 1 || \strlen($value) % 4 === 1) {
            return null;
        }
        $data = \strlen($value) % 4 === 0 ? rtrim($value, '=') : $value;
        if (str_contains($data, '=') || \strlen($data) % 4 === 1) {
            return null;
        }
        $decoded = base64_decode($data . str_repeat('=', (4 - \strlen($data) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
