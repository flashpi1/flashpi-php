<?php

declare(strict_types=1);

namespace Flashpi\Tests\Resources;

use Flashpi\ApiResponse;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;

/**
 * `$flashpi->status` (Python: tests/test_resources.py).
 */
final class StatusResourceTest extends TestCase
{
    public function testReturnsTheApiStatus(): void
    {
        $api = self::api([Replies::json(['status' => 'operational'])]);
        self::assertSame(['status' => 'operational'], $api->client->status->get());
        self::assertSentOnly($api, 'GET', '/v1/status');
    }

    public function testReturnsTheResponseWithWithResponse(): void
    {
        $api = self::api([Replies::json(['status' => 'operational'], 200, ['X-Request-Id' => 'req_status'])]);
        $result = $api->client->status->withResponse()->get(['language' => 'es']);
        self::assertInstanceOf(ApiResponse::class, $result);
        self::assertSame(['status' => 'operational'], $result->data);
        self::assertSame('req_status', $result->requestId);
        self::assertSame(200, $result->response->getStatusCode());
        self::assertSame('es', $api->request(0)->getHeaderLine('accept-language'));
    }
}
