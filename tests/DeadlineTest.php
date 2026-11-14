<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Exception\ApiException;
use Flashpi\Exception\ConnectionException;
use Flashpi\Exception\TimeoutException;
use Flashpi\Flashpi;
use Flashpi\Scripts\OpenApi\Downloader;
use Flashpi\Tests\Support\FakeClock;
use Flashpi\Tests\Support\LocalServer;
use Flashpi\Tests\Support\MockApi;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use Flashpi\Tests\Support\TrickleStream;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\StreamHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use Psr\Http\Message\ResponseInterface;

/**
 * The timeout of an attempt is one deadline, from sending it to reading the whole answer, as the
 * Node SDK's AbortController: an answer that trickles in times out like one that never comes
 * (Python: tests/test_deadline.py).
 */
final class DeadlineTest extends TestCase
{
    private const BODY = ['{"status"', ':', '"operational"', '}'];

    public static function tearDownAfterClass(): void
    {
        LocalServer::stop();
    }

    // On a fake clock.

    public function testTimesOutAnAnswerThatTricklesInPastTheDeadline(): void
    {
        $clock = new FakeClock();
        $body = new TrickleStream($clock, self::BODY, 0.4);
        $api = self::api([self::streamed($body)], ['timeout' => 1.0, 'maxRetries' => 0], $clock);
        self::throws(TimeoutException::class, static fn() => $api->things->status(), '/timed out after 1 seconds$/');
        self::assertCount(1, $api->sent);
        self::assertTrue($body->closed);
        self::assertSame(1.2, $clock->elapsed());
    }

    public function testRetriesItWithTheSameIdempotencyKey(): void
    {
        $clock = new FakeClock();
        $api = self::api([self::streamed(new TrickleStream($clock, self::BODY, 0.6)), Replies::json(['ok' => true])], ['timeout' => 1.0], $clock);
        self::assertSame(['ok' => true], $api->things->issue([]));
        self::assertCount(2, $api->sent);
        self::assertCount(1, array_unique($api->headers('idempotency-key')));
        self::assertSame(1.7, $clock->elapsed());
    }

    public function testReadsAnAnswerThatArrivesInTime(): void
    {
        $clock = new FakeClock();
        $body = new TrickleStream($clock, self::BODY, 0.2);
        $api = self::api([self::streamed($body)], ['timeout' => 1.0], $clock);
        $result = $api->things->withResponse()->get('x');
        self::assertSame(['status' => 'operational'], $result->data);
        self::assertSame(implode('', self::BODY), (string) $result->response->getBody());
    }

    public function testTimesOutAnAnswerThatAMiddlewareOfYourClientRead(): void
    {
        $clock = new FakeClock();
        $body = new TrickleStream($clock, self::BODY, 0.4);
        $readTheBody = static function (HandlerStack $stack): void {
            $stack->push(\GuzzleHttp\Middleware::mapResponse(static function (ResponseInterface $response): ResponseInterface {
                $response->getBody()->getContents();

                return $response;
            }));
        };
        $api = new MockApi([self::streamed($body)], ['timeout' => 1.0, 'maxRetries' => 0], $clock, $readTheBody);
        self::throws(TimeoutException::class, static fn() => $api->things->status(), '/timed out after 1 seconds$/');
    }

    public function testTimesOutAnEmptyAnswerWhoseEndArrivesLate(): void
    {
        $clock = new FakeClock();
        $late = static function () use ($clock): Response {
            $clock->advance(2);

            return new Response(200);
        };
        $api = self::api([$late], ['timeout' => 1.0, 'maxRetries' => 0], $clock);
        self::throws(TimeoutException::class, static fn() => $api->things->status());
    }

    public function testTimesOutAnErrorAnswerThatTricklesIn(): void
    {
        $clock = new FakeClock();
        $api = self::api([new Response(500, [], new TrickleStream($clock, self::BODY, 0.4))], ['timeout' => 1.0, 'maxRetries' => 0], $clock);
        self::throws(TimeoutException::class, static fn() => $api->things->status());
    }

    public function testTurnsABodyThatFailsWhileReadIntoAConnectionError(): void
    {
        $clock = new FakeClock();
        $body = new TrickleStream($clock, self::BODY, 0.1);
        $body->close();
        $api = self::api([self::streamed($body)], ['maxRetries' => 0], $clock);
        self::throws(ConnectionException::class, static fn() => $api->things->status(), '/^GET https:\/\/api\.flashpi\.com\.ec\/v1\/status failed: The connection was closed$/');
    }

    // On real sockets.

    #[Group('network')]
    public function testTheCurlHandlerTimesOutATrickleWithinTheDeadline(): void
    {
        $client = new Flashpi(Replies::TEST_KEY, baseUrl: LocalServer::url(), timeout: 1.0, maxRetries: 0);
        $started = microtime(true);
        $error = self::throws(TimeoutException::class, static fn() => self::trickle($client, 0.4), '/timed out after 1 seconds$/');
        self::assertNull($error->getPrevious());
        // curl stops the transfer at the deadline, before the last pieces arrive.
        self::assertLessThan(1.5, microtime(true) - $started);
    }

    #[Group('network')]
    public function testTheStreamHandlerTimesOutATrickleToo(): void
    {
        $http = new Client(['handler' => HandlerStack::create(new StreamHandler())]);
        $client = new Flashpi(Replies::TEST_KEY, baseUrl: LocalServer::url(), timeout: 1.0, maxRetries: 0, httpClient: $http);
        self::throws(TimeoutException::class, static fn() => self::trickle($client, 0.4), '/timed out after 1 seconds$/');
        self::throws(TimeoutException::class, static fn() => self::get($client, '/slow-headers?delay=1.5'));
    }

    #[Group('network')]
    public function testReadsAnAnswerThatArrivesInTimeOverASocket(): void
    {
        $client = new Flashpi(Replies::TEST_KEY, baseUrl: LocalServer::url(), timeout: 5.0, maxRetries: 0);
        self::assertSame(['status' => 'operational'], self::trickle($client, 0.05));
        self::assertSame(['status' => 'operational'], self::get($client, '/gzip'));
        $http = new Client(['handler' => HandlerStack::create(new StreamHandler())]);
        $stream = new Flashpi(Replies::TEST_KEY, baseUrl: LocalServer::url(), timeout: 5.0, httpClient: $http);
        self::assertSame(['status' => 'operational'], self::trickle($stream, 0.05));
    }

    #[Group('network')]
    public function testRetriesAnAnswerCutShortBeforeItsContentLengthWithEveryHandler(): void
    {
        foreach (['stream' => new StreamHandler(), 'default' => null] as $label => $handler) {
            $attempts = 0;
            $stack = HandlerStack::create($handler);
            $stack->push(Middleware::tap(static function () use (&$attempts): void {
                $attempts++;
            }));
            $client = new Flashpi(Replies::TEST_KEY, baseUrl: LocalServer::url(), timeout: 5.0, maxRetries: 1, httpClient: new Client(['handler' => $stack]));
            MockApi::engine($client)->useClock(new FakeClock());
            $error = self::throws(ConnectionException::class, static fn() => self::get($client, '/short'));
            self::assertNotInstanceOf(TimeoutException::class, $error, $label);
            self::assertStringStartsWith('GET ' . LocalServer::url() . '/short failed: ', $error->getMessage(), $label);
            self::assertSame(2, $attempts, $label);
            // A decoded body has no Content-Length of its own to fall short of.
            self::assertSame(['status' => 'operational'], self::get($client, '/gzip'), $label);
        }
    }

    #[Group('network')]
    public function testSendsTheRequestAndNeverFollowsARedirect(): void
    {
        $client = new Flashpi(Replies::TEST_KEY, baseUrl: LocalServer::url(), maxRetries: 0);
        $echo = self::get($client, '/echo');
        self::assertSame('GET', \is_array($echo) ? $echo['method'] ?? null : null);
        self::assertSame('Bearer ' . Replies::TEST_KEY, \is_array($echo) ? $echo['authorization'] ?? null : null);
        $error = self::throws(ApiException::class, static fn() => self::get($client, '/redirect'));
        self::assertSame(302, $error->status);
        self::assertSame('https://example.com/elsewhere', $error->header('location'));
    }

    #[Group('network')]
    public function testTurnsARefusedConnectionIntoAConnectionError(): void
    {
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        fclose($probe);
        $client = new Flashpi(Replies::TEST_KEY, baseUrl: "http://{$name}", maxRetries: 0);
        $error = self::throws(ConnectionException::class, static fn() => self::get($client, '/ok'));
        self::assertNotInstanceOf(TimeoutException::class, $error);
        self::assertStringStartsWith("GET http://{$name}/ok failed: ", $error->getMessage());
    }

    #[Group('network')]
    public function testTheContractDownloaderFetchesWithoutFollowingRedirects(): void
    {
        $redirect = Downloader::fetch(LocalServer::url() . '/redirect');
        self::assertSame([302, 'https://example.com/elsewhere'], [$redirect->status, $redirect->location]);
        $answer = Downloader::fetch(LocalServer::url() . '/gzip');
        self::assertSame(200, $answer->status);
        self::assertSame(gzencode('{"status":"operational"}'), $answer->body);
        self::throws(\RuntimeException::class, static fn() => Downloader::fetch('http://127.0.0.1:1/'), '/^Could not download/');
    }

    private static function streamed(TrickleStream $body): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    private static function trickle(Flashpi $client, float $delay): mixed
    {
        return self::get($client, "/trickle?delay={$delay}");
    }

    /**
     * A GET of a path of the local server, through the client's engine.
     */
    private static function get(Flashpi $client, string $path): mixed
    {
        [$route, $query] = explode('?', $path, 2) + ['', ''];
        parse_str($query, $parameters);
        $values = [];
        foreach ($parameters as $name => $value) {
            $values[(string) $name] = $value;
        }
        $spec = new \Flashpi\Internal\RequestSpec('GET', $route, query: $values);

        return MockApi::engine($client)->request($spec)->data;
    }
}
