<?php

declare(strict_types=1);

namespace Flashpi\Tests\Types\Fixtures;

use Flashpi\ApiResponse;
use Flashpi\Exception\ApiException;
use Flashpi\Exception\ConnectionException;
use Flashpi\Exception\RateLimitException;
use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Flashpi;
use Flashpi\Page;
use Flashpi\Types\ApiTypes;
use Flashpi\Webhooks;

/**
 * The client's API used the way a consumer uses it. PHPStan analyses it at level max with the rest
 * of the tests: all of it must pass.
 *
 * @phpstan-import-type Document from ApiTypes
 */
final class ClientConsumer
{
    public static function client(): Flashpi
    {
        return new Flashpi(
            apiKey: 'fp_test_key',
            timeout: 30,
            maxRetries: 0,
            language: 'es',
            defaultHeaders: ['X-Tenant' => 'acme'],
        );
    }

    /**
     * @param Page<Document> $documents
     *
     * @return list<string>
     */
    public static function accessKeys(Page $documents): array
    {
        $keys = [];
        foreach ($documents as $document) {
            $keys[] = $document['accessKey'];
        }
        $next = $documents->hasNextPage() ? $documents->nextPage() : null;

        return [...$keys, ...array_column($next->data ?? [], 'accessKey')];
    }

    /**
     * @param ApiResponse<Document> $result
     */
    public static function requestId(ApiResponse $result): string
    {
        return ($result->requestId ?? '-') . ' ' . $result->response->getStatusCode() . ' ' . $result->data['status'];
    }

    public static function explain(\Throwable $error): string
    {
        return match (true) {
            $error instanceof RateLimitException => 'wait ' . ($error->retryAfter() ?? 0) . ' s',
            $error instanceof ApiException => "{$error->status} {$error->errorCode} " . implode(', ', array_column($error->errors, 'field')),
            $error instanceof ConnectionException => 'try again with ' . ($error->idempotencyKey ?? 'a new key'),
            default => $error->getMessage(),
        };
    }

    /**
     * @param array<string, list<string>> $headers
     *
     * @return array{challenge: string}|null
     */
    public static function webhook(string $body, array $headers, string $secret): ?array
    {
        try {
            $event = Flashpi::unwrapWebhook($body, $headers, $secret);
        } catch (WebhookVerificationException $error) {
            return $error->reason === WebhookVerificationException::INVALID_SECRET ? null : ['challenge' => ''];
        }
        if ($event['type'] === 'webhook_endpoint.verification') {
            return ['challenge' => $event['data']['challenge']];
        }
        (new Webhooks())->verifySignature($body, $headers, $secret, tolerance: 60, now: new \DateTimeImmutable());

        return null;
    }
}
