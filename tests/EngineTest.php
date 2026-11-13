<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Exception\ApiException;
use Flashpi\Exception\ConnectionException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\TimeoutException;
use Flashpi\Internal\ClientConfig;
use Flashpi\Internal\Engine;
use Flashpi\Internal\Multipart;
use Flashpi\Internal\Path;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Internal\SystemClock;
use Flashpi\Tests\Support\FakeClock;
use Flashpi\Tests\Support\Forms;
use Flashpi\Tests\Support\MockApi;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\Secrets;
use Flashpi\Tests\Support\TestCase;
use Flashpi\Tests\Support\Things;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;

/**
 * The parts of the request logic that need no API (Python: tests/test_http.py).
 */
final class EngineTest extends TestCase
{
    public function testEncodesEachIdLikeEncodeUriComponent(): void
    {
        self::assertSame('/v1/issuers/a%2Fb%3Fc', Path::of('/v1/issuers/{}', 'a/b?c'));
        self::assertSame('/v1/a/x%20y/b/%C3%B1', Path::of('/v1/a/{}/b/{}', 'x y', 'ñ'));
        self::assertSame("/v1/a/-_.!~*'()", Path::of('/v1/a/{}', "-_.!~*'()"));
        self::assertSame('/v1/status', Path::of('/v1/status'));
    }

    public function testRefusesMissingAndDotSegmentIds(): void
    {
        self::throws(FlashpiException::class, static fn() => Path::of('/v1/a/{}', ''), "/^Missing id in \\/v1\\/a\\/\\{id\\}: got ''$/");
        self::throws(FlashpiException::class, static fn() => Path::of('/v1/a/{}', "\t"), "/got '\\\\t'$/");
        self::throws(FlashpiException::class, static fn() => Path::of('/v1/a/{}', '..'), "/^Invalid id in \\/v1\\/a\\/\\{id\\}: '\\.\\.'$/");
    }

    public function testRefusesATemplateThatDoesNotMatchTheIds(): void
    {
        self::throws(\InvalidArgumentException::class, static fn() => Path::of('/v1/a/{}', 'x', 'y'), '/^\/v1\/a\/\{\} has 1 placeholders, not 2$/');
    }

    public function testAppliesTheHeadersFromTheLowestPriorityToTheHighest(): void
    {
        $api = self::api([Replies::json([], 201)], ['language' => 'es', 'defaultHeaders' => ['Accept' => 'text/plain', 'X-Team' => 'a']]);
        $api->things->create([], [
            'language' => 'en',
            'extraHeaders' => ['x-team' => 'b', 'Content-Type' => 'text/plain', 'user-agent' => 'mine'],
            'correlationId' => 'c-1',
            'idempotencyKey' => 'key-1',
        ]);
        $request = $api->request(0);
        $expected = [
            'accept' => 'text/plain',
            'user-agent' => 'mine',
            'accept-language' => 'en',
            'x-team' => 'b',
            'content-type' => 'application/json',
            'x-correlation-id' => 'c-1',
            'idempotency-key' => 'key-1',
            'authorization' => 'Bearer ' . Replies::TEST_KEY,
        ];
        foreach ($expected as $name => $value) {
            self::assertSame([$value], $request->getHeader($name), $name);
        }
    }

    public function testLeavesTheContentTypeOfAMultipartBodyToItsBoundary(): void
    {
        $api = self::api([Replies::json([])]);
        $api->things->upload('p12', 'secret', ['extraHeaders' => ['content-type' => 'text/plain']]);
        self::assertStringStartsWith('multipart/form-data; boundary=', $api->request(0)->getHeaderLine('content-type'));
    }

    public function testNeverShowsACertificateOrItsPassword(): void
    {
        $form = new Multipart(['password' => 'MyP12Passw0rd'], ['file' => ['filename' => 'certificate.p12', 'contents' => "0\x82P12-CONTENT", 'contentType' => 'application/x-pkcs12']]);
        $spec = new RequestSpec('POST', '/v1/issuers/i/certificates', multipart: $form);
        foreach ([$form, $spec] as $object) {
            self::assertFalse(Secrets::inDumps($object, 'MyP12Passw0rd'));
            self::assertFalse(Secrets::inDumps($object, 'P12-CONTENT'));
            self::assertStringNotContainsString('MyP12Passw0rd', var_export($object, true));
        }
        self::assertSame(['names' => ['password', 'file']], $form->__debugInfo());
        self::assertSame('MyP12Passw0rd', $form->parts()[0]['contents'] ?? null);
    }

    public function testEncodesTheFileNameOfAPartAsHtmlForms(): void
    {
        self::assertSame('firma ñ.p12', Multipart::encode('firma ñ.p12'));
        self::assertSame('a%22b\\\\c%0D%0A%09%1B%7Fd/e.p12', Multipart::encode("a\"b\\c\r\n\t\x1B\x7Fd/e.p12"));
        $form = new Multipart(['password' => 'x'], ['file' => ['filename' => "../a\"b\r\n.p12", 'contents' => 'p12', 'contentType' => 'application/x-pkcs12']]);
        $request = new Request('POST', 'https://h', ['Content-Type' => 'multipart/form-data; boundary=b'], new MultipartStream($form->parts(), 'b'));
        self::assertSame(
            [['form-data; name="password"', null, 'x'], ['form-data; name="file"; filename="../a%22b%0D%0A.p12"', 'application/x-pkcs12', 'p12']],
            array_map(static fn(array $part): array => array_values($part), Forms::parts($request)),
        );
    }

    public function testKeepsAnUploadsSecretsOutOfTheTraceOfItsError(): void
    {
        $api = self::api(array_fill(0, 3, Replies::networkError()));
        $error = self::throws(ConnectionException::class, static fn() => $api->things->upload("0\x82P12-CONTENT", 'MyP12Passw0rd'));
        self::assertSame([], Secrets::inException($error, 'MyP12Passw0rd'));
        self::assertSame([], Secrets::inException($error, 'P12-CONTENT'));
    }

    public function testNeverShowsTheKeyOfTheConfig(): void
    {
        $config = new ClientConfig(Replies::TEST_KEY, 'https://h', 60.0, 2, null, ['X-Team' => 'secret-team']);
        self::assertFalse(Secrets::inDumps($config, Replies::TEST_KEY));
        self::assertSame(['baseUrl' => 'https://h', 'timeout' => 60.0, 'maxRetries' => 2, 'language' => null, 'defaultHeaders' => ['X-Team']], $config->__debugInfo());
        self::assertSame('Bearer ' . Replies::TEST_KEY, $config->authorization());
        self::assertSame('test', $config->environment());
        $engine = new Engine($config, new Client());
        self::assertFalse(Secrets::inDumps($engine, Replies::TEST_KEY));
        self::assertSame(['config' => $config], $engine->__debugInfo());
    }

    public function testBacksOffFromHalfASecondTo8SecondsWithUpTo25PercentOfJitter(): void
    {
        $clock = new FakeClock();
        $engine = new Engine(new ClientConfig(Replies::TEST_KEY, 'https://h', 60.0, 2, null, []), new Client(), $clock);
        self::assertSame([0.5, 1.0, 2.0, 4.0, 8.0, 8.0], array_map($engine->backoff(...), range(0, 5)));
        self::assertSame(8.0, $engine->backoff(10000));
        $clock->jitter = 0.999;
        self::assertEqualsWithDelta(0.375, $engine->backoff(0), 0.001);
        self::assertEqualsWithDelta(6.002, $engine->backoff(4), 0.001);
    }

    public function testDecidesWhatToRetry(): void
    {
        $engine = new Engine(new ClientConfig(Replies::TEST_KEY, 'https://h', 60.0, 2, null, []), new Client(), new FakeClock());
        $delay = static function (int $status, ?string $code = null, ?string $retryAfter = null) use ($engine): ?float {
            $headers = $retryAfter === null ? [] : ['retry-after' => [$retryAfter]];

            return $engine->retryDelay(ApiException::fromResponse($status, $headers, $code === null ? '' : (string) json_encode(['code' => $code])), 0);
        };
        self::assertSame(1.0, $engine->retryDelay(new ConnectionException('x'), 1));
        self::assertSame(2.0, $engine->retryDelay(new TimeoutException('x'), 2));
        self::assertNull($engine->retryDelay(new FlashpiException('not JSON'), 0));
        self::assertSame(0.5, $delay(408));
        self::assertSame(0.5, $delay(429));
        self::assertSame(60.0, $delay(429, null, '60'));
        self::assertNull($delay(429, null, '61'));
        self::assertSame(0.0, $delay(429, null, '0'));
        self::assertSame(0.5, $delay(500));
        self::assertNull($delay(501));
        self::assertNull($delay(503, 'demo_issuer_unavailable'));
        self::assertSame(1.0, $delay(409, 'idempotency_key_in_progress'));
        self::assertNull($delay(409, 'sequential_already_used'));
        self::assertNull($delay(400));
        self::assertNull($delay(404));
        self::assertNull($delay(422));
    }

    public function testTheSystemClockSleepsAndDraws(): void
    {
        $clock = new SystemClock();
        $before = $clock->now();
        $clock->sleep(0.01);
        $clock->sleep(0.0);
        self::assertGreaterThanOrEqual(0.009, $clock->now() - $before);
        for ($index = 0; $index < 100; $index++) {
            $random = $clock->random();
            self::assertGreaterThanOrEqual(0.0, $random);
            self::assertLessThan(1.0, $random);
        }
    }

    public function testAResourceCanBuildNestedResources(): void
    {
        $api = self::api([Replies::json(['id' => 'x'])]);
        $parent = new class (MockApi::engine($api->client)) extends Resource {
            public readonly Things $children;

            public function __construct(Engine $engine)
            {
                parent::__construct($engine);
                $this->children = new Things($engine);
            }
        };
        self::assertSame(['id' => 'x'], $parent->children->get('x'));
    }

    public function testSyncTimeoutFloor(): void
    {
        self::assertSame(0.0, RequestSpec::syncTimeoutFloor(null));
        self::assertSame(0.0, RequestSpec::syncTimeoutFloor(['options' => ['waitForAuthorization' => 'yes']]));
        self::assertSame(30.0, RequestSpec::syncTimeoutFloor(['options' => ['waitForAuthorization' => true]]));
        self::assertSame(16.5, RequestSpec::syncTimeoutFloor(['options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => 1.5]]));
        self::assertSame(30.0, RequestSpec::syncTimeoutFloor(['options' => ['waitForAuthorization' => true, 'waitTimeoutSeconds' => true]]));
    }
}
