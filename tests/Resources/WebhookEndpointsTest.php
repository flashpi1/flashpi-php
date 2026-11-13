<?php

declare(strict_types=1);

namespace Flashpi\Tests\Resources;

use Flashpi\ApiResponse;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `$flashpi->webhookEndpoints` and its deliveries (Python: tests/test_resources.py's cases and
 * TestWebhookEndpoints).
 */
final class WebhookEndpointsTest extends TestCase
{
    private const ENDPOINT = 'whe_1';

    private const DELIVERY = 'del_1';

    private const EVENT = 'evt_1';

    /**
     * Each method, its arguments, the request it sends (method, path, query, JSON body) and its
     * answer.
     *
     * @return iterable<string, array{string, list<mixed>, string, string, array<string, string>, string|null, Response}>
     */
    public static function operations(): iterable
    {
        $endpoint = '/v1/webhook-endpoints/' . self::ENDPOINT;
        $delivery = "{$endpoint}/deliveries/" . self::DELIVERY;
        yield 'create' => ['webhookEndpoints.create', [['url' => 'https://x']], 'POST', '/v1/webhook-endpoints', [], '{"url":"https://x"}', Replies::json([], 201)];
        yield 'list' => ['webhookEndpoints.list', [], 'GET', '/v1/webhook-endpoints', [], null, Replies::page([])];
        yield 'get' => ['webhookEndpoints.get', [self::ENDPOINT], 'GET', $endpoint, [], null, Replies::json([])];
        yield 'update' => ['webhookEndpoints.update', [self::ENDPOINT, ['disabled' => true]], 'PATCH', $endpoint, [], '{"disabled":true}', Replies::json([])];
        yield 'delete' => ['webhookEndpoints.delete', [self::ENDPOINT], 'DELETE', $endpoint, [], null, new Response(204)];
        yield 'getSecret' => ['webhookEndpoints.getSecret', [self::ENDPOINT], 'GET', "{$endpoint}/secret", [], null, Replies::json([])];
        yield 'rotateSecret' => ['webhookEndpoints.rotateSecret', [self::ENDPOINT, ['previousSecretExpiresInHours' => 0]], 'POST', "{$endpoint}/rotate-secret", [], '{"previousSecretExpiresInHours":0}', Replies::json([])];
        yield 'rotateSecret without a body' => ['webhookEndpoints.rotateSecret', [self::ENDPOINT], 'POST', "{$endpoint}/rotate-secret", [], null, Replies::json([])];
        yield 'verify' => ['webhookEndpoints.verify', [self::ENDPOINT], 'POST', "{$endpoint}/verify", [], null, Replies::json([], 202)];
        yield 'deliveries.list' => ['webhookEndpoints.deliveries.list', [self::ENDPOINT, ['status' => 'failed']], 'GET', "{$endpoint}/deliveries", ['status' => 'failed'], null, Replies::page([])];
        yield 'deliveries.get' => ['webhookEndpoints.deliveries.get', [self::ENDPOINT, self::DELIVERY], 'GET', $delivery, [], null, Replies::json([])];
        yield 'deliveries.replay' => ['webhookEndpoints.deliveries.replay', [self::ENDPOINT, self::DELIVERY], 'POST', "{$delivery}/replay", [], null, Replies::json([], 202)];
    }

    /**
     * @param list<mixed> $arguments
     * @param array<string, string> $query
     */
    #[DataProvider('operations')]
    public function testSendsItsOperation(string $name, array $arguments, string $method, string $path, array $query, ?string $json, Response $reply): void
    {
        $api = self::api([$reply]);
        $result = $api->op($name, ...$arguments);
        self::assertSentOnly($api, $method, $path, $query, $json);
        if ($method === 'DELETE') {
            self::assertNull($result);
        }
    }

    /**
     * @param list<mixed> $arguments
     * @param array<string, string> $query
     */
    #[DataProvider('operations')]
    public function testReturnsTheResponseWithWithResponse(string $name, array $arguments, string $method, string $path, array $query, ?string $json, Response $reply): void
    {
        $api = self::api([$reply]);
        $result = $api->opWithResponse($name, ...$arguments);
        self::assertInstanceOf(ApiResponse::class, $result);
        self::assertSame($reply->getStatusCode(), $result->response->getStatusCode());
        self::assertSame($path, $api->request(0)->getUri()->getPath());
    }

    public function testReadsTheSigningSecret(): void
    {
        $secret = ['secret' => 'whsec_abc', 'previousSecretExpiresAt' => null];
        $api = self::api([Replies::json($secret)]);
        self::assertSame($secret, $api->client->webhookEndpoints->getSecret(self::ENDPOINT));
    }

    public function testWalksTheDeliveriesOfAnEndpoint(): void
    {
        $api = self::api([Replies::page([['id' => 'del_1']], 'c1'), Replies::page([['id' => 'del_2']])]);
        $params = ['status' => 'failed', 'eventType' => 'invoice.authorized', 'eventId' => self::EVENT];
        $ids = [];
        foreach ($api->client->webhookEndpoints->deliveries->list(self::ENDPOINT, $params) as $delivery) {
            $ids[] = $delivery['id'];
        }
        self::assertSame(['del_1', 'del_2'], $ids);
        self::assertSame([...$params, 'cursor' => 'c1'], self::queries($api)[1] ?? null);
        self::assertSame('/v1/webhook-endpoints/' . self::ENDPOINT . '/deliveries', $api->request(1)->getUri()->getPath());
    }
}
