<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\ApiResponse;
use Flashpi\Exception\FlashpiException;
use Flashpi\Flashpi;
use Flashpi\Internal\Engine;
use Flashpi\Resources\Invoices;
use Flashpi\Tests\Support\Forms;
use Flashpi\Tests\Support\MockApi;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\Secrets;
use Flashpi\Tests\Support\TestCase;
use Flashpi\Version;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The client and the requests it sends (Python: tests/test_client.py).
 *
 * @phpstan-import-type Reply from MockApi
 */
final class ClientTest extends TestCase
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    // The client.

    public function testTakesTheKeyAndTellsTheEnvironmentFromItsPrefix(): void
    {
        self::assertSame('test', (new Flashpi(Replies::TEST_KEY))->environment);
        self::assertSame('live', (new Flashpi(apiKey: Replies::LIVE_KEY))->environment);
    }

    public function testFallsBackToTheEnvironmentVariable(): void
    {
        putenv('FLASHPI_API_KEY=' . Replies::LIVE_KEY);
        self::assertSame('live', (new Flashpi())->environment);
        self::assertSame('live', (new Flashpi(timeout: 1))->environment);
        self::assertSame('test', (new Flashpi(Replies::TEST_KEY))->environment);
        $_ENV['FLASHPI_API_KEY'] = Replies::TEST_KEY;
        self::assertSame('test', (new Flashpi())->environment);
        unset($_ENV['FLASHPI_API_KEY']);
        putenv('FLASHPI_API_KEY');
        $_SERVER['FLASHPI_API_KEY'] = Replies::LIVE_KEY;
        self::assertSame('live', (new Flashpi())->environment);
    }

    public function testRefusesAMissingKey(): void
    {
        self::throws(FlashpiException::class, static fn() => new Flashpi(), '/^Missing API key/');
        putenv('FLASHPI_API_KEY=  ');
        self::throws(FlashpiException::class, static fn() => new Flashpi(), '/^Missing API key/');
        self::throws(FlashpiException::class, static fn() => new Flashpi(" \n"), '/^Missing API key/');
        self::throws(FlashpiException::class, static fn() => new Flashpi(''), '/^Missing API key/');
    }

    public function testRefusesAWebhookSecretOrAnotherPrefix(): void
    {
        self::throws(FlashpiException::class, static fn() => new Flashpi('whsec_abc'), '/webhook signing secret/');
        self::throws(FlashpiException::class, static fn() => new Flashpi('sk_test_123'), '/fp_test_ or fp_live_/');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeyCharacters(): iterable
    {
        foreach (['LF' => "\n", 'CRLF' => "\r\n", 'NUL' => "\0", 'space' => ' ', 'tab' => "\t", 'DEL' => "\x7f", 'ñ' => 'ñ', 'not UTF-8' => "\xff"] as $name => $inner) {
            yield $name => [$inner];
        }
    }

    #[DataProvider('badKeyCharacters')]
    public function testRefusesAKeyWithCharactersAKeyCannotHaveWithoutShowingIt(string $inner): void
    {
        foreach (["fp_live_SECRETPART{$inner}SECRETTAIL", "fp_test_SECRETPART{$inner}TAIL"] as $key) {
            $error = self::throws(FlashpiException::class, static fn() => new Flashpi($key), '/copy it again/');
            self::assertSame([], Secrets::inException($error, 'SECRET'));
            self::assertStringNotContainsString('SECRET', (string) $error);
            self::assertNull($error->getPrevious());
        }
    }

    public function testRefusesSuchAKeyFromTheEnvironmentVariable(): void
    {
        putenv("FLASHPI_API_KEY=fp_live_SECRETPART\tSECRETTAIL");
        $error = self::throws(FlashpiException::class, static fn() => new Flashpi(), '/copy it again/');
        self::assertSame([], Secrets::inException($error, 'SECRET'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function badSettings(): iterable
    {
        yield 'baseUrl' => [['baseUrl' => 'api.flashpi.com.ec']];
        yield 'timeout' => [['timeout' => -1.0]];
        yield 'maxRetries' => [['maxRetries' => -1]];
        yield 'header' => [['defaultHeaders' => ['X-Tenant' => "a\nb"]]];
        yield 'idempotency' => [['defaultHeaders' => ['Idempotency-Key' => 'k']]];
        yield 'language' => [['language' => "e\ns"]];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('badSettings')]
    public function testKeepsTheKeyOutOfTheTraceOfASettingsError(array $settings): void
    {
        putenv('FLASHPI_API_KEY=' . Replies::LIVE_KEY);
        foreach ([null, Replies::TEST_KEY] as $apiKey) {
            $error = self::throws(\Throwable::class, static fn() => MockApi::flashpi(['apiKey' => $apiKey, ...$settings]));
            self::assertTrue($error instanceof FlashpiException || $error instanceof \InvalidArgumentException);
            self::assertSame([], Secrets::inException($error, Replies::LIVE_KEY));
            self::assertSame([], Secrets::inException($error, Replies::TEST_KEY));
        }
    }

    public function testKeepsARefusedKeyOutOfTheTraceOfItsError(): void
    {
        foreach (["fp_live_SECRETPART\nSECRETTAIL", 'whsec_SECRETPART', 'sk_live_SECRETPART'] as $key) {
            $error = self::throws(FlashpiException::class, static fn() => new Flashpi($key));
            self::assertSame([], Secrets::inException($error, 'SECRETPART'));
            putenv("FLASHPI_API_KEY={$key}");
            $error = self::throws(FlashpiException::class, static fn() => new Flashpi());
            self::assertSame([], Secrets::inException($error, 'SECRETPART'));
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSettings(): iterable
    {
        yield 'zero timeout' => [['timeout' => 0.0], 'timeout must be more than 0'];
        yield 'negative timeout' => [['timeout' => -1.0], 'timeout'];
        yield 'NaN timeout' => [['timeout' => \NAN], 'timeout'];
        yield 'infinite timeout' => [['timeout' => \INF], 'timeout'];
        yield 'timeout past the limit' => [['timeout' => 2 ** 31 / 1000], 'at most 2147483.647 seconds'];
        yield 'negative maxRetries' => [['maxRetries' => -1], 'maxRetries must be a whole number, 0 or more'];
        yield 'ftp baseUrl' => [['baseUrl' => 'ftp://api.flashpi.com.ec'], 'baseUrl'];
        yield 'baseUrl without scheme' => [['baseUrl' => 'api.flashpi.com.ec'], "baseUrl must be an http:// or https:// URL, got 'api.flashpi.com.ec'"];
        yield 'baseUrl without host' => [['baseUrl' => 'https://'], 'baseUrl'];
        yield 'broken baseUrl' => [['baseUrl' => 'http://[::1'], 'baseUrl'];
        yield 'idempotency key' => [['defaultHeaders' => ['IDEMPOTENCY-KEY' => 'always-the-same']], 'Idempotency-Key'];
    }

    /**
     * @param array<string, mixed> $settings
     */
    #[DataProvider('invalidSettings')]
    public function testValidatesItsSettings(array $settings, string $message): void
    {
        $error = self::throws(FlashpiException::class, static fn() => MockApi::flashpi(['apiKey' => Replies::TEST_KEY, ...$settings]));
        self::assertStringContainsString($message, $error->getMessage());
    }

    public function testRefusesSettingsOfTheWrongType(): void
    {
        self::throws(\TypeError::class, static fn() => new Flashpi(Replies::TEST_KEY, timeout: '60')); // @phpstan-ignore argument.type
        self::throws(\TypeError::class, static fn() => new Flashpi(Replies::TEST_KEY, maxRetries: 1.5)); // @phpstan-ignore argument.type
        self::throws(\TypeError::class, static fn() => new Flashpi(Replies::TEST_KEY, httpClient: new \stdClass())); // @phpstan-ignore argument.type
        self::throws(
            \InvalidArgumentException::class,
            static fn() => new Flashpi(Replies::TEST_KEY, defaultHeaders: ['x-number' => 1]), // @phpstan-ignore argument.type
            "/^The value of header 'x-number' must be a string, got int$/",
        );
    }

    public function testAcceptsTheLongestTimeoutAndExplicitNullAsTheDefaults(): void
    {
        self::assertSame('test', (new Flashpi(Replies::TEST_KEY, timeout: 2147483.647))->environment);
        $client = new Flashpi(Replies::TEST_KEY, baseUrl: null, timeout: null, maxRetries: null, language: null, defaultHeaders: null, httpClient: null);
        self::assertSame(['environment' => 'test', 'baseUrl' => Flashpi::DEFAULT_BASE_URL], $client->__debugInfo());
    }

    public function testNeverShowsTheKey(): void
    {
        $client = new Flashpi(Replies::TEST_KEY, defaultHeaders: ['X-Team' => 'billing']);
        $engine = MockApi::engine($client);
        foreach ([$client, $engine, $engine->config()] as $object) {
            self::assertFalse(Secrets::inDumps($object, Replies::TEST_KEY), $object::class);
            self::assertStringNotContainsString(Replies::TEST_KEY, (string) json_encode($object));
        }
        self::assertStringNotContainsString(Replies::TEST_KEY, var_export($client, true));
        $this->expectException(\Exception::class);
        serialize($client);
    }

    public function testNeverShowsTheKeyInItsResourcesOrTheirViews(): void
    {
        $client = new Flashpi(Replies::TEST_KEY);
        $objects = [
            $client->invoices,
            $client->invoices->withResponse(),
            $client->documents,
            $client->issuers,
            $client->issuers->establishments->emissionPoints,
            $client->issuers->certificates->withResponse(),
            $client->issuerOnboardings,
            $client->webhookEndpoints->deliveries->withResponse(),
            $client->events,
            $client->status,
        ];
        foreach ($objects as $object) {
            self::assertFalse(Secrets::inDumps($object, Replies::TEST_KEY), $object::class);
            self::assertStringNotContainsString(Replies::TEST_KEY, var_export($object, true));
        }
        self::assertSame([], $client->issuers->__debugInfo());
        self::assertSame(['resource' => Invoices::class], $client->invoices->withResponse()->__debugInfo());
    }

    public function testDoesNotFollowRedirectsWithItsOwnHttpClient(): void
    {
        $http = (new \ReflectionProperty(Engine::class, 'http'))->getValue(MockApi::engine(new Flashpi(Replies::TEST_KEY)));
        self::assertInstanceOf(Client::class, $http);
        $config = (new \ReflectionProperty(Client::class, 'config'))->getValue($http);
        self::assertIsArray($config);
        self::assertFalse($config['allow_redirects'] ?? null);
    }

    // The requests.

    public function testSendsTheKeyJsonHeadersAndTheSdkVersion(): void
    {
        $api = self::api([Replies::json(['id' => Replies::ID])]);
        self::assertSame(['id' => Replies::ID], $api->things->get(Replies::ID));
        $request = $api->request(0);
        self::assertSame('https://api.flashpi.com.ec/v1/things/' . Replies::ID, (string) $request->getUri());
        self::assertSame('GET', $request->getMethod());
        self::assertSame('Bearer ' . Replies::TEST_KEY, $request->getHeaderLine('authorization'));
        self::assertSame('application/json', $request->getHeaderLine('accept'));
        self::assertSame('flashpi-php/' . Version::VERSION, $request->getHeaderLine('user-agent'));
        self::assertFalse($request->hasHeader('accept-language'));
        self::assertFalse($request->hasHeader('idempotency-key'));
        self::assertFalse($request->hasHeader('content-type'));
        self::assertSame('', (string) $request->getBody());
    }

    /**
     * @return iterable<string, array{\Closure(): list<mixed>}>
     */
    public static function failingReplies(): iterable
    {
        yield 'answer' => [static fn(): array => [Replies::problem(404, 'not_found')]];
        yield 'connection' => [static fn(): array => array_fill(0, 3, Replies::networkError())];
        yield 'retried 5xx' => [static fn(): array => array_fill(0, 3, Replies::problem(503, 'unavailable'))];
        yield 'transfer' => [static fn(): array => array_fill(0, 3, new \GuzzleHttp\Exception\RequestException('closed', new \GuzzleHttp\Psr7\Request('GET', '/')))];
    }

    /**
     * @param \Closure(): list<Reply> $replies
     */
    #[DataProvider('failingReplies')]
    public function testKeepsTheKeyOutOfTheTraceOfARequestError(\Closure $replies): void
    {
        foreach ([false, true] as $withResponse) {
            $api = self::api($replies());
            $error = self::throws(FlashpiException::class, static fn() => $withResponse
                ? $api->things->withResponse()->issue(['a' => 1])
                : $api->things->issue(['a' => 1]));
            self::assertCount(\count($replies()), $api->sent);
            self::assertSame([], Secrets::inException($error, Replies::TEST_KEY));
            self::assertNull($error->getPrevious());
        }
    }

    public function testTrimsSpacesAroundAPastedKey(): void
    {
        $api = self::api([Replies::json([])], ['apiKey' => '  ' . Replies::TEST_KEY . "\n"]);
        $api->things->status();
        self::assertSame(['Bearer ' . Replies::TEST_KEY], $api->headers('authorization'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pastedKeys(): iterable
    {
        yield 'byte order marks' => ["\u{FEFF}" . Replies::TEST_KEY . "\u{FEFF}"];
        yield 'alternating' => [" \u{FEFF} \u{FEFF}" . Replies::TEST_KEY];
        yield 'unicode spaces' => ["\u{3000}" . Replies::TEST_KEY . "\u{2028}\u{A0}"];
        yield 'vertical tab and form feed' => ["\v\f" . Replies::TEST_KEY . "\v"];
    }

    #[DataProvider('pastedKeys')]
    public function testTrimsWhatJavaScriptsTrimRemovesAroundAKey(string $pasted): void
    {
        $api = self::api([Replies::json([])], ['apiKey' => $pasted]);
        self::assertSame('test', $api->client->environment);
        $api->things->status();
        self::assertSame(['Bearer ' . Replies::TEST_KEY], $api->headers('authorization'));
    }

    public function testTrimsAByteOrderMarkAroundTheEnvironmentVariable(): void
    {
        putenv("FLASHPI_API_KEY=\u{FEFF}" . Replies::TEST_KEY . "\n");
        $api = self::api([Replies::json([])], ['apiKey' => null]);
        self::assertSame('test', $api->client->environment);
        $api->things->status();
        self::assertSame(['Bearer ' . Replies::TEST_KEY], $api->headers('authorization'));
    }

    public function testSendsAJsonBodyWithAnIdempotencyKeyOnPost(): void
    {
        $api = self::api([Replies::json(['id' => Replies::ID], 201)]);
        $api->things->create(['ruc' => '0993411372001', 'name' => 'Panadería Ñandú', 'url' => 'https://x.ec/a']);
        $request = $api->request(0);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('application/json', $request->getHeaderLine('content-type'));
        self::assertMatchesRegularExpression(self::UUID, $request->getHeaderLine('idempotency-key'));
        self::assertSame('{"ruc":"0993411372001","name":"Panadería Ñandú","url":"https://x.ec/a"}', (string) $request->getBody());
    }

    public function testSendsAnEmptyArrayAsTheEmptyObjectAndAJsonSerializableAsItSays(): void
    {
        $api = self::api([Replies::json([], 201), Replies::json([], 201), Replies::json([], 201)]);
        $api->things->create([]);
        $api->things->create(['items' => [], 'tags' => ['a']]);
        $api->things->create(new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['amount' => '1.00'];
            }
        });
        self::assertSame(['{}', '{"items":[],"tags":["a"]}', '{"amount":"1.00"}'], array_map(
            static fn($request): string => (string) $request->getBody(),
            $api->sent,
        ));
    }

    public function testSendsEveryNestedEmptyArrayAsAnObjectButTheContractsLists(): void
    {
        $api = self::api([Replies::json([], 201), Replies::json([], 201), Replies::json([], 200)]);
        $api->things->issue(['issuerId' => 'x', 'additionalInfo' => [], 'options' => [], 'items' => [['description' => 'a', 'additionalDetails' => []]], 'payments' => []]);
        $api->things->create(['items' => [[]], 'enabledEvents' => [], 'metadata' => ['items' => []], 'list' => [[], ['a']]]);
        $api->things->update(Replies::ID, ['taxProfile' => [], 'branding' => []]);
        self::assertSame([
            '{"issuerId":"x","additionalInfo":{},"options":{},"items":[{"description":"a","additionalDetails":{}}],"payments":[]}',
            '{"items":[{}],"enabledEvents":[],"metadata":{"items":[]},"list":[{},["a"]]}',
            '{"taxProfile":{},"branding":{}}',
        ], array_map(static fn($request): string => (string) $request->getBody(), $api->sent));
    }

    public function testSendsNoBodyWhenThereIsNone(): void
    {
        $api = self::api([Replies::json([], 202)]);
        $api->things->create(null);
        $request = $api->request(0);
        self::assertSame('', (string) $request->getBody());
        self::assertFalse($request->hasHeader('content-type'));
        self::assertTrue($request->hasHeader('idempotency-key'));
    }

    public function testUsesAFreshKeyPerCallAndTheCallersKeyWhenGiven(): void
    {
        $api = self::api([Replies::json([], 201), Replies::json([], 201), Replies::json([], 201)]);
        $api->things->create([]);
        $api->things->create([]);
        $api->things->create([], ['idempotencyKey' => 'order-42']);
        [$first, $second, $third] = $api->headers('idempotency-key') + ['', '', ''];
        self::assertNotSame($first, $second);
        self::assertSame('order-42', $third);
    }

    public function testNeverSendsAnIdempotencyKeyOnPatchDeleteOrGet(): void
    {
        $api = self::api([Replies::json([]), new Response(204), Replies::json([])]);
        $api->things->update(Replies::ID, [], ['idempotencyKey' => 'ignored']);
        $api->things->delete(Replies::ID, ['idempotencyKey' => 'ignored']);
        $api->things->get(Replies::ID, ['idempotencyKey' => 'ignored']);
        self::assertSame(['', '', ''], $api->headers('idempotency-key'));
        self::assertSame(['PATCH', 'DELETE', 'GET'], array_map(static fn($request): string => $request->getMethod(), $api->sent));
    }

    public function testAsksForTheClientLanguageAndLetsARequestOverrideIt(): void
    {
        $api = self::api([Replies::json([]), Replies::json([])], ['language' => 'es']);
        $api->things->status();
        $api->things->status(['language' => 'en']);
        self::assertSame(['es', 'en'], $api->headers('accept-language'));
    }

    public function testSendsTheCorrelationIdAndCustomHeadersButNeverReplacesTheKey(): void
    {
        $api = self::api([Replies::json([])], ['defaultHeaders' => ['x-team' => 'billing']]);
        $api->things->status([
            'correlationId' => 'order-42',
            'extraHeaders' => ['x-extra' => 'yes', 'Authorization' => 'Bearer other'],
        ]);
        $request = $api->request(0);
        self::assertSame('order-42', $request->getHeaderLine('x-correlation-id'));
        self::assertSame('billing', $request->getHeaderLine('x-team'));
        self::assertSame('yes', $request->getHeaderLine('x-extra'));
        self::assertSame(['Bearer ' . Replies::TEST_KEY], $request->getHeader('authorization'));
    }

    public function testRefusesHeaderValuesThatAreNotTextBeforeSending(): void
    {
        $api = self::api();
        self::throws(
            \InvalidArgumentException::class,
            static fn() => $api->things->status(['extraHeaders' => ['x-number' => 1]]),
            "/^The value of header 'x-number' must be a string, got int$/",
        );
        self::throws(
            \InvalidArgumentException::class,
            static fn() => $api->things->status(['extraHeaders' => ['x-list' => ['a']]]),
            '/must be a string, got array/',
        );
        self::assertSame([], $api->sent);
    }

    public function testKeepsABaseUrlPathAndDropsItsTrailingSlash(): void
    {
        $api = self::api([Replies::json([])], ['baseUrl' => 'http://localhost:3000/proxy/']);
        $api->things->status();
        self::assertSame('http://localhost:3000/proxy/v1/status', (string) $api->request(0)->getUri());
    }

    public function testReturnsTheResponseAndRequestIdWithWithResponse(): void
    {
        $api = self::api([Replies::json(['id' => Replies::ID], 200, ['X-Request-Id' => 'req_abc'])]);
        $result = $api->things->withResponse()->get(Replies::ID);
        self::assertInstanceOf(ApiResponse::class, $result);
        self::assertSame(['id' => Replies::ID], $result->data);
        self::assertSame(200, $result->response->getStatusCode());
        self::assertSame('req_abc', $result->requestId);
        self::assertSame('{"id":"' . Replies::ID . '"}', (string) $result->response->getBody());
        self::assertSame('{"id":"' . Replies::ID . '"}', $result->response->getBody()->__toString());
    }

    public function testRefusesAViewCallThatSentNoRequestOrNamesNoMethod(): void
    {
        $api = self::api();
        self::throws(FlashpiException::class, static fn() => $api->things->withResponse()->nothing(), '/sent no request/');
        self::throws(\BadMethodCallException::class, static fn() => $api->things->withResponse()->missing(), '/has no method missing\(\)/'); // @phpstan-ignore method.notFound
        self::throws(\BadMethodCallException::class, static fn() => $api->things->withResponse()->send(), '/has no method send\(\)/'); // @phpstan-ignore method.notFound
        self::throws(\BadMethodCallException::class, static fn() => $api->things->withResponse()->withResponse()); // @phpstan-ignore method.notFound
        self::assertSame(['resource' => \Flashpi\Tests\Support\Things::class], $api->things->withResponse()->__debugInfo());
        self::assertSame([], $api->things->__debugInfo());
    }

    public function testReadsTextAndBytesAnswers(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><factura>Ñandú</factura>';
        $pdf = "%PDF-1.7\n\xff\xfe binary";
        $api = self::api([
            new Response(200, ['Content-Type' => 'application/xml'], $xml),
            new Response(200, ['Content-Type' => 'application/pdf'], $pdf),
        ]);
        self::assertSame($xml, $api->things->text(Replies::ID));
        self::assertSame($pdf, $api->things->raw(Replies::ID));
        self::assertSame(['application/xml, application/problem+json', 'application/pdf, application/problem+json'], $api->headers('accept'));
    }

    public function testReturnsNullForAnEmptyOrNoContentAnswer(): void
    {
        $api = self::api([new Response(204), new Response(200), new Response(202), new Response(200, [], '{"ignored":true}')]);
        self::assertNull($api->things->delete(Replies::ID));
        self::assertNull($api->things->get(Replies::ID));
        self::assertNull($api->things->create([]));
        self::assertNull($api->things->delete(Replies::ID));
    }

    public function testSendsAMultipartBodyBuiltAgainForEveryAttempt(): void
    {
        $api = self::api([Replies::networkError('reset'), Replies::json(['id' => Replies::ID], 202)]);
        $content = implode('', array_map('chr', range(0, 255)));
        $api->things->upload($content, 'secret ñ', ['extraHeaders' => ['content-type' => 'text/plain']]);
        self::assertCount(2, $api->sent);
        foreach ($api->sent as $request) {
            $parts = Forms::parts($request);
            self::assertSame(['form-data; name="password"', 'form-data; name="file"; filename="certificate.p12"'], array_column($parts, 'disposition'));
            self::assertSame(['secret ñ', $content], array_column($parts, 'body'));
            self::assertSame('application/x-pkcs12', $parts[1]['type'] ?? null);
        }
        self::assertCount(1, array_unique($api->headers('idempotency-key')));
    }

    public function testRejectsEmptyPathIdsBeforeSendingAnything(): void
    {
        $api = self::api();
        foreach (['', '  ', "\n"] as $value) {
            self::throws(FlashpiException::class, static fn() => $api->things->get($value), '/^Missing id in \/v1\/things\/\{id\}: got /');
        }
        self::throws(FlashpiException::class, static fn() => $api->things->getNested(Replies::ID, ''), "/^Missing id in \\/v1\\/things\\/\\{id\\}\\/parts\\/\\{id\\}: got ''$/");
        self::throws(\TypeError::class, static fn() => $api->things->get(null)); // @phpstan-ignore argument.type
        self::assertSame([], $api->sent);
    }

    public function testEncodesPathIdsLikeEncodeUriComponent(): void
    {
        $api = self::api([Replies::json([]), Replies::json([])]);
        $api->things->get('a/b?c#d e');
        $api->things->get("ñ!~*'()");
        self::assertSame('/v1/things/a%2Fb%3Fc%23d%20e', $api->request(0)->getUri()->getPath());
        self::assertSame("/v1/things/%C3%B1!~*'()", $api->request(1)->getUri()->getPath());
    }

    public function testThrowsAFlashpiExceptionWhenASuccessBodyIsNotJson(): void
    {
        $api = self::api([new Response(200, [], '<html>')]);
        $error = self::throws(FlashpiException::class, static fn() => $api->things->status(), '/^The API answered 200 with a body that is not JSON$/');
        self::assertInstanceOf(\JsonException::class, $error->getPrevious());
        self::assertCount(1, $api->sent);
    }

    public function testRefusesUnknownRequestOptions(): void
    {
        $api = self::api();
        self::throws(
            \InvalidArgumentException::class,
            static fn() => $api->things->create([], ['idempotencykey' => 'typo', 'retries' => 1]),
            '/^Unknown request options: idempotencykey, retries$/',
        );
        self::assertSame([], $api->sent);
    }

    public function testRefusesABodyThatIsNotJson(): void
    {
        $api = self::api();
        self::throws(\InvalidArgumentException::class, static fn() => $api->things->create(['amount' => \NAN]), '/cannot be sent as JSON: Inf and NaN/');
        self::throws(\InvalidArgumentException::class, static fn() => $api->things->create(['name' => "\xff"]), '/cannot be sent as JSON/');
        self::assertSame([], $api->sent);
    }
}
