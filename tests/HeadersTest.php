<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Exception\BadRequestException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Flashpi;
use Flashpi\Tests\Support\MockApi;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The values that become headers (options, extra and default headers, the language) are checked
 * before anything is sent, as the Node SDK's `Headers` does: the spaces around a value are
 * trimmed, and a line break, another control character or a bad name fails at once instead of
 * reaching the HTTP client, which would refuse it on every attempt or send it (Python:
 * tests/test_headers.py).
 */
final class HeadersTest extends TestCase
{
    private const BAD_VALUES = ['injection' => "a\r\nX-Injected: 1", 'LF' => "a\nb", 'CR' => "a\rb", 'NUL' => "a\0b", 'VT' => "a\x0bb", 'FF' => "a\x0cb", 'DEL' => "a\x7fb"];

    private const BAD_NAMES = ['space' => 'x tenant', 'colon' => 'x:t', 'empty' => '', 'line break' => "x\r\ny", 'ñ' => 'x-ñ', 'parentheses' => '(x)', 'trailing line break' => "x-a\n"];

    private const FRAMING = ['Content-Length', 'content-length', 'Transfer-Encoding', 'TRANSFER-ENCODING'];

    // Trimming.

    public function testTrimsTheSpacesAroundTheValuesOfEveryOption(): void
    {
        $api = self::api([Replies::json([], 201)], ['defaultHeaders' => ['x-env' => " prod\t"], 'language' => ' es ']);
        $api->things->create([], [
            'idempotencyKey' => ' order-42 ',
            'correlationId' => "\torder-42\r\n",
            'extraHeaders' => ['x-tenant' => ' acme ', 'x-tab' => "v\t"],
        ]);
        $request = $api->request(0);
        self::assertSame('order-42', $request->getHeaderLine('idempotency-key'));
        self::assertSame('order-42', $request->getHeaderLine('x-correlation-id'));
        self::assertSame('acme', $request->getHeaderLine('x-tenant'));
        self::assertSame('v', $request->getHeaderLine('x-tab'));
        self::assertSame('prod', $request->getHeaderLine('x-env'));
        self::assertSame('es', $request->getHeaderLine('accept-language'));
    }

    public function testKeepsTabsSpacesAndUtf8TextInsideAValue(): void
    {
        $api = self::api([Replies::json([]), Replies::json([], 201)], ['defaultHeaders' => ['x-env' => 'Peñafiel']]);
        $api->things->status(['extraHeaders' => ['x-note' => "a b\tc", 'x-name' => 'Ñandú']]);
        $api->things->create([], ['extraHeaders' => ['x-name' => 'Ñandú']]);
        self::assertSame("a b\tc", $api->request(0)->getHeaderLine('x-note'));
        self::assertSame(['Ñandú', 'Ñandú'], $api->headers('x-name'));
        self::assertSame(['Peñafiel', 'Peñafiel'], $api->headers('x-env'));
    }

    public function testTakesTheLastSpellingOfARepeatedIdempotencyKeyHeader(): void
    {
        $api = self::api([Replies::problem(400, 'validation_failed')]);
        $error = self::throws(BadRequestException::class, static fn() => $api->things->create([], [
            'extraHeaders' => ['Idempotency-Key' => 'order-1', 'idempotency-key' => 'order-2'],
        ]));
        self::assertSame(['order-2'], $api->request(0)->getHeader('idempotency-key'));
        self::assertSame('order-2', $error->idempotencyKey);
    }

    public function testTakesTheTrimmedIdempotencyKeyHeaderAsTheKey(): void
    {
        $api = self::api([Replies::json([], 201)]);
        $api->things->create([], ['extraHeaders' => ['Idempotency-Key' => ' order-7 ']]);
        self::assertSame(['order-7'], $api->request(0)->getHeader('idempotency-key'));
    }

    public function testSendsNoLanguageWhenItIsEmpty(): void
    {
        $api = self::api([Replies::json([]), Replies::json([])], ['language' => ' ']);
        $api->things->status();
        $api->things->status(['language' => '']);
        self::assertSame(['', ''], $api->headers('accept-language'));
        $client = self::api([Replies::json([])], ['language' => 'es']);
        $client->things->status(['language' => '  ']);
        self::assertSame(['es'], $client->headers('accept-language'));
    }

    // Refusing before sending.

    /**
     * @return iterable<string, array{string}>
     */
    public static function badValues(): iterable
    {
        foreach (self::BAD_VALUES as $name => $value) {
            yield $name => [$value];
        }
    }

    #[DataProvider('badValues')]
    public function testRefusesAControlCharacterInAnyValueWithoutRetrying(string $value): void
    {
        $api = self::api();
        foreach (['idempotencyKey', 'correlationId', 'language'] as $option) {
            $error = self::throws(\InvalidArgumentException::class, static fn() => $api->things->create([], [$option => $value]), "/^{$option} has a line break or another control character$/");
            self::assertStringNotContainsString('X-Injected', $error->getMessage());
        }
        self::throws(
            \InvalidArgumentException::class,
            static fn() => $api->things->create([], ['extraHeaders' => ['x-tenant' => $value]]),
            "/^The value of header 'x-tenant' has a line break or another control character$/",
        );
        self::assertSame([], $api->sent);
        self::assertSame([], $api->clock->sleeps);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badNames(): iterable
    {
        foreach (self::BAD_NAMES as $name => $value) {
            yield $name => [$value];
        }
    }

    #[DataProvider('badNames')]
    public function testRefusesABadHeaderName(string $name): void
    {
        $api = self::api();
        self::throws(\InvalidArgumentException::class, static fn() => $api->things->status(['extraHeaders' => [$name => 'v']]), '/is not a valid header name$/');
        self::assertSame([], $api->sent);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function framing(): iterable
    {
        foreach (self::FRAMING as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('framing')]
    public function testRefusesAHeaderThatFramesTheBody(string $name): void
    {
        $api = self::api();
        foreach (['0', '99', 'chunked'] as $value) {
            $message = "/^'{$name}' is set by the SDK from the body: leave it out$/";
            self::throws(\InvalidArgumentException::class, static fn() => $api->things->create(['a' => 1], ['extraHeaders' => [$name => $value]]), $message);
            self::throws(\InvalidArgumentException::class, static fn() => $api->things->status(['extraHeaders' => [$name => $value]]), $message);
            self::throws(\InvalidArgumentException::class, static fn() => $api->things->upload('p12', 'secret', ['extraHeaders' => [$name => $value]]), $message);
        }
        self::assertSame([], $api->sent);
    }

    public function testRefusesAnOptionThatIsNotText(): void
    {
        $api = self::api();
        foreach (['idempotencyKey', 'correlationId', 'language'] as $option) {
            self::throws(\InvalidArgumentException::class, static fn() => $api->things->create([], [$option => 123]), "/^{$option} must be a string, got int$/");
        }
        self::assertSame([], $api->sent);
    }

    public function testRefusesExtraHeadersThatAreNotAnArrayOrNamesThatAreNotText(): void
    {
        $api = self::api();
        self::throws(\InvalidArgumentException::class, static fn() => $api->things->status(['extraHeaders' => 'x-a: b']), '/^extraHeaders must be an array of header names to values$/');
        self::throws(\InvalidArgumentException::class, static fn() => $api->things->status(['extraHeaders' => ['b']]), '/^Header names must be strings, got int$/');
        self::assertSame([], $api->sent);
    }

    // The client's settings.

    #[DataProvider('badValues')]
    public function testRefusesAControlCharacterInDefaultHeadersOrTheLanguage(string $value): void
    {
        self::throws(\InvalidArgumentException::class, static fn() => new Flashpi(Replies::TEST_KEY, defaultHeaders: ['x-env' => $value]), "/^The value of header 'x-env' has a line break/");
        self::throws(\InvalidArgumentException::class, static fn() => MockApi::flashpi(['apiKey' => Replies::TEST_KEY, 'language' => $value]), '/^language has a line break/');
    }

    #[DataProvider('badNames')]
    public function testRefusesABadDefaultHeaderName(string $name): void
    {
        self::throws(\InvalidArgumentException::class, static fn() => new Flashpi(Replies::TEST_KEY, defaultHeaders: [$name => 'v']), '/is not a valid header name$/');
    }

    #[DataProvider('framing')]
    public function testRefusesADefaultHeaderThatFramesTheBody(string $name): void
    {
        self::throws(\InvalidArgumentException::class, static fn() => new Flashpi(Replies::TEST_KEY, defaultHeaders: [$name => 'chunked']), "/^'{$name}' is set by the SDK from the body/");
    }

    public function testRefusesAnIdempotencyKeyInDefaultHeadersEvenWithSpaces(): void
    {
        self::throws(FlashpiException::class, static fn() => new Flashpi(Replies::TEST_KEY, defaultHeaders: ['Idempotency-Key' => ' k ']), '/Idempotency-Key/');
    }

    public function testEscapesABadNameInTheMessage(): void
    {
        self::throws(\InvalidArgumentException::class, static fn() => new Flashpi(Replies::TEST_KEY, defaultHeaders: ["x\r\ny"  => 'v']), "/^'x\\\\r\\\\ny' is not a valid header name$/");
    }
}
