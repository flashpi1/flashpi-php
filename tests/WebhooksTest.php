<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Exception\WebhookVerificationException;
use Flashpi\Flashpi;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\Secrets;
use Flashpi\Webhooks;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Webhook verification, with the example of the Standard Webhooks specification and the one of
 * Flashpi's docs (Python: tests/test_webhooks.py).
 *
 * @phpstan-type Vector array{secret: string, id: string, timestamp: string, body: string, signature: string}
 */
final class WebhooksTest extends TestCase
{
    /** The example of the Standard Webhooks specification (24-byte key). */
    private const STANDARD = [
        'secret' => 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw',
        'id' => 'msg_p5jXN8AQM9LWM0D4loKWxJek',
        'timestamp' => '1614265330',
        'body' => '{"test": 2432232314}',
        'signature' => 'v1,g0hM9SsE+OTPJTGt/tmIKtSyZlE3uFJELVlNIOLJ1OE=',
    ];

    /** The example of Flashpi's webhook docs (32-byte key), the one the API's tests sign. */
    private const FLASHPI = [
        'secret' => 'whsec_Zmxhc2hwaS1kb2NzLWV4YW1wbGUtc2VjcmV0LTMyLWI=',
        'id' => '019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b',
        'timestamp' => '1792152000',
        'body' => '{"id":"019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b","type":"invoice.authorized","environment":"test","createdAt":"2026-10-16T17:20:00.000Z"}',
        'signature' => 'v1,HbWOpDPWCo3wtq41t9GVHUpg69M1zgvveoDJmSWkA8U=',
    ];

    private const DECOY = 'v1,Ceo5qEr07ixe2NLpvHk3FH9bwy/WavXrAFQ/9tdO6mc=';

    // verifySignature().

    public function testAcceptsTheStandardWebhooksExample(): void
    {
        self::assertSame('verified', self::reason(self::STANDARD['body'], self::headers(self::STANDARD), self::STANDARD['secret'], now: self::at(self::STANDARD)));
    }

    public function testAcceptsTheExampleOfFlashpisDocs(): void
    {
        self::assertSame('verified', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
    }

    public function testSignsTheRawBytesOfAUtf8Body(): void
    {
        $body = '{"id":"019a1b2c-3d4e-7f60-8a1b-2c3d4e5f6a7b","type":"invoice.authorized","environment":"test",'
            . '"createdAt":"2026-10-16T17:20:00.000Z","data":{"counterparty":{"name":"Panadería Ñandú €"}}}';
        self::assertSame(191, \strlen($body));
        $headers = self::headers(self::FLASHPI, 'v1,fdAGYFcvd+G8WvVy6jtQtuduKm8mENxuA/zTqcWYSJY=');
        foreach ([$body, Utils::streamFor($body)] as $payload) {
            self::assertSame('verified', self::reason($payload, $headers, self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
        }
    }

    public function testAcceptsABodyOfAnyBytes(): void
    {
        $headers = self::headers(self::STANDARD, 'v1,sEcZ042G8Crqgxbybb3y0eDxQR3TcfYsk11I1XULhSI=');
        $body = implode('', array_map('chr', range(0, 255)));
        self::assertSame('verified', self::reason($body, $headers, self::STANDARD['secret'], now: self::at(self::STANDARD)));
    }

    public function testAcceptsEitherSecretDuringARotation(): void
    {
        $rotation = self::FLASHPI['signature'] . ' v1,dZdgetSIp5ZOSjhz50Gcz1N1hb/WBvK3IdncOP52NrQ=';
        foreach ([self::FLASHPI['secret'], self::STANDARD['secret']] as $secret) {
            self::assertSame('verified', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI, $rotation), $secret, now: self::at(self::FLASHPI)));
        }
    }

    public function testSkipsDecoysMalformedEntriesAndOtherVersions(): void
    {
        $signature = implode(' ', [self::DECOY, 'v2,' . substr(self::FLASHPI['signature'], 3), 'v1', 'v1,not-base64!', '', self::FLASHPI['signature']]);
        self::assertSame('verified', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI, $signature), self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
    }

    public function testAcceptsTheSecretWithoutItsPrefixAndWithSpacesAroundIt(): void
    {
        $secret = ' ' . substr(self::FLASHPI['secret'], 6) . "\n";
        self::assertSame('verified', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), $secret, now: self::at(self::FLASHPI)));
        // What JavaScript's trim() removes, as the Node SDK trims it.
        foreach ([self::FLASHPI['secret'] . "\u{A0}", self::FLASHPI['secret'] . "\f", "\u{FEFF}" . self::FLASHPI['secret'], "\u{3000}" . self::FLASHPI['secret'] . "\u{2028}"] as $secret) {
            self::assertSame('verified', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), $secret, now: self::at(self::FLASHPI)));
        }
        self::assertSame('invalid_secret', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), "\u{A0}\u{FEFF}", now: self::at(self::FLASHPI)));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function badSignatures(): iterable
    {
        yield 'altered body' => [str_replace('test', 'live', self::FLASHPI['body']), self::FLASHPI['signature']];
        yield 'other version' => [self::FLASHPI['body'], 'v2,' . substr(self::FLASHPI['signature'], 3)];
        yield 'truncated' => [self::FLASHPI['body'], substr(self::FLASHPI['signature'], 0, -4)];
        yield 'empty' => [self::FLASHPI['body'], 'v1,'];
        yield 'garbage' => [self::FLASHPI['body'], 'v1,dawfeoifkpqwoekfpqoekf'];
    }

    #[DataProvider('badSignatures')]
    public function testRejectsBadSignatures(string $body, string $signature): void
    {
        self::assertSame('no_matching_signature', self::reason($body, self::headers(self::FLASHPI, $signature), self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
    }

    public function testAcceptsASignatureWithoutItsPaddingLikeAtob(): void
    {
        $headers = self::headers(self::FLASHPI, rtrim(self::FLASHPI['signature'], '='));
        self::assertSame('verified', self::reason(self::FLASHPI['body'], $headers, self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
    }

    public function testRejectsABodySignedWithAnotherSecret(): void
    {
        self::assertSame('no_matching_signature', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), self::STANDARD['secret'], now: self::at(self::FLASHPI)));
    }

    public function testAcceptsTimestampsUpTo5MinutesAwayAndRejectsOthers(): void
    {
        self::assertSame(300, Webhooks::TOLERANCE_SECONDS);
        $check = static fn(int $offset): string => self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], now: self::at(self::FLASHPI, $offset));
        self::assertSame(['verified', 'verified', 'timestamp_out_of_range', 'timestamp_out_of_range'], [$check(300), $check(-300), $check(301), $check(-301)]);
    }

    public function testTakesAnotherToleranceAndADateAsNow(): void
    {
        $now = new \DateTimeImmutable('@' . self::at(self::FLASHPI, 11));
        try {
            (new Webhooks())->verifySignature(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], tolerance: 10, now: $now);
            self::fail('A stale webhook was accepted');
        } catch (WebhookVerificationException $error) {
            self::assertSame('timestamp_out_of_range', $error->reason);
            self::assertSame('The webhook-timestamp is more than 10 seconds away from now', $error->getMessage());
        }
        $later = new \DateTimeImmutable('@' . self::at(self::FLASHPI, 10));
        self::assertSame('verified', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], 10.5, $later));
        self::assertSame('verified', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], 0, self::at(self::FLASHPI) + 0.9));
    }

    public function testChecksTheTimestampAgainstTheCurrentClockByDefault(): void
    {
        self::assertSame('timestamp_out_of_range', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function headerNames(): iterable
    {
        yield 'id' => ['webhook-id'];
        yield 'timestamp' => ['webhook-timestamp'];
        yield 'signature' => ['webhook-signature'];
    }

    #[DataProvider('headerNames')]
    public function testRejectsARequestWithoutAHeader(string $name): void
    {
        $headers = self::headers(self::FLASHPI);
        unset($headers[$name]);
        self::assertSame('missing_headers', self::reason(self::FLASHPI['body'], $headers, self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
        self::assertSame('missing_headers', self::reason(self::FLASHPI['body'], [...self::headers(self::FLASHPI), $name => ''], self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTimestamps(): iterable
    {
        foreach (['abc', '-1', '1792152000.5', '1234567890123', '١٧٩', ' 1792152000', "1792152000\n"] as $timestamp) {
            yield (string) json_encode($timestamp) => [$timestamp];
        }
    }

    #[DataProvider('badTimestamps')]
    public function testRejectsAnInvalidTimestamp(string $timestamp): void
    {
        $headers = [...self::headers(self::FLASHPI), 'webhook-timestamp' => $timestamp];
        self::assertSame('invalid_timestamp', self::reason(self::FLASHPI['body'], $headers, self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badSecrets(): iterable
    {
        foreach (['', 'whsec_', '   ', 'whsec_not base64!', 'whsec_a', 'whsec_ab=', 'whsec_Zmxhc2hw', 'whsec_test', 'whsec_' . base64_encode(str_repeat('x', 65))] as $secret) {
            yield var_export($secret, true) => [$secret];
        }
        yield 'null' => [null];
        yield 'false' => [false];
    }

    #[DataProvider('badSecrets')]
    public function testRefusesAnInvalidSecret(mixed $secret): void
    {
        self::assertSame('invalid_secret', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), $secret, now: self::at(self::FLASHPI)));
    }

    public function testReportsABadSecretBeforeAStaleTimestamp(): void
    {
        self::assertSame('invalid_secret', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), 'whsec_test'));
    }

    public function testAcceptsA64ByteKeyAndAnUnpaddedSecret(): void
    {
        $key = str_repeat("\x07", 64);
        $headers = self::headers(self::FLASHPI, self::sign($key, self::FLASHPI, self::FLASHPI['body']));
        self::assertSame('verified', self::reason(self::FLASHPI['body'], $headers, 'whsec_' . base64_encode($key), now: self::at(self::FLASHPI)));
        self::assertSame('verified', self::reason(self::FLASHPI['body'], self::headers(self::FLASHPI), rtrim(self::FLASHPI['secret'], '='), now: self::at(self::FLASHPI)));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function parsedBodies(): iterable
    {
        yield 'decoded' => [json_decode(self::FLASHPI['body'], true)];
        yield 'null' => [null];
        yield 'number' => [42];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('parsedBodies')]
    public function testRefusesABodyThatWasAlreadyDecoded(mixed $payload): void
    {
        self::assertSame('invalid_payload', self::reason($payload, self::headers(self::FLASHPI), self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
    }

    public function testReadsHeadersInAnyLetterCaseAndJoinsRepeatedSignatures(): void
    {
        $repeated = static function (): \Generator {
            yield 'webhook-id' => self::FLASHPI['id'];
            yield 'webhook-timestamp' => self::FLASHPI['timestamp'];
            yield 'webhook-signature' => self::DECOY;
            yield 'webhook-signature' => self::FLASHPI['signature'];
        };
        $request = new ServerRequest('POST', '/webhooks', [
            'Webhook-Id' => self::FLASHPI['id'],
            'Webhook-Timestamp' => self::FLASHPI['timestamp'],
            'Webhook-Signature' => [self::DECOY, self::FLASHPI['signature']],
        ], self::FLASHPI['body']);
        $variants = [
            'case' => ['Webhook-Id' => self::FLASHPI['id'], 'WEBHOOK-TIMESTAMP' => self::FLASHPI['timestamp'], 'webhook-signature' => self::FLASHPI['signature']],
            'lists' => [
                'webhook-id' => [self::FLASHPI['id'], 'ignored'],
                'webhook-timestamp' => [self::FLASHPI['timestamp']],
                'webhook-signature' => [self::DECOY, null, 7, self::FLASHPI['signature']],
                'other' => null,
                0 => 'numeric name',
            ],
            'iterator' => new \ArrayIterator([...self::headers(self::FLASHPI), 'webhook-signature' => new \ArrayIterator([self::FLASHPI['signature']])]),
            'repeated names' => $repeated(),
            'PSR-7' => $request->getHeaders(),
        ];
        foreach ($variants as $name => $headers) {
            self::assertSame('verified', self::reason(self::FLASHPI['body'], $headers, self::FLASHPI['secret'], now: self::at(self::FLASHPI)), $name);
        }
        self::assertSame('verified', self::reason($request->getBody(), $request->getHeaders(), self::FLASHPI['secret'], now: self::at(self::FLASHPI)));
    }

    public function testRefusesToleranceAndNowThatAreNotFiniteInsteadOfSkippingTheTimeCheck(): void
    {
        foreach ([[\NAN, null], [-1, null], [\INF, null], [null, \NAN], [null, \INF], [null, -\INF]] as [$tolerance, $now]) {
            try {
                (new Webhooks())->verifySignature(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], $tolerance, $now ?? self::at(self::FLASHPI));
                self::fail('Accepted a tolerance or now that is not finite');
            } catch (\InvalidArgumentException $error) {
                self::assertMatchesRegularExpression('/^(tolerance must be a number of seconds, 0 or more|now must be a date or a number of Unix seconds)$/', $error->getMessage());
            }
        }
        $this->expectException(\TypeError::class);
        (new Webhooks())->verifySignature(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], '300'); // @phpstan-ignore argument.type
    }

    // unwrap().

    public function testReturnsTheVerifiedEvent(): void
    {
        $event = (new Webhooks())->unwrap(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], now: self::at(self::FLASHPI));
        self::assertSame(json_decode(self::FLASHPI['body'], true), $event);
        self::assertSame('invoice.authorized', $event['type']);
    }

    public function testIsAvailableStaticallyAndOnTheClient(): void
    {
        $client = new Flashpi(Replies::TEST_KEY);
        $event = $client->webhooks->unwrap(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], now: self::at(self::FLASHPI));
        self::assertSame(self::FLASHPI['id'], $event['id']);
        $event = Flashpi::unwrapWebhook(Utils::streamFor(self::FLASHPI['body']), self::headers(self::FLASHPI), self::FLASHPI['secret'], now: self::at(self::FLASHPI));
        self::assertSame(self::FLASHPI['id'], $event['id']);
        Flashpi::verifyWebhookSignature(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], now: self::at(self::FLASHPI));
        $client->webhooks->verifySignature(self::FLASHPI['body'], self::headers(self::FLASHPI), self::FLASHPI['secret'], now: self::at(self::FLASHPI));
        $this->expectException(WebhookVerificationException::class);
        Flashpi::verifyWebhookSignature(self::FLASHPI['body'], self::headers(self::FLASHPI, self::DECOY), self::FLASHPI['secret'], now: self::at(self::FLASHPI));
    }

    public function testReadsTheBodyOnceAndDecodesTheBytesItVerified(): void
    {
        $headers = self::headers(self::FLASHPI);
        $now = self::at(self::FLASHPI);
        $event = (new Webhooks())->unwrap(new NoSeekStream(Utils::streamFor(self::FLASHPI['body'])), $headers, self::FLASHPI['secret'], now: $now);
        self::assertSame('invoice.authorized', $event['type']);

        // A body that would read otherwise the second time is decoded as it was verified.
        $changing = new class (self::FLASHPI['body']) implements \Stringable {
            private int $reads = 0;

            public function __construct(private readonly string $body) {}

            public function __toString(): string
            {
                return $this->reads++ === 0 ? $this->body : str_replace('019a1b2c', 'forged00', $this->body);
            }
        };
        $event = (new Webhooks())->unwrap($changing, $headers, self::FLASHPI['secret'], now: $now);
        self::assertSame(self::FLASHPI['id'], $event['id']);
    }

    public function testThrowsBeforeDecodingWhenTheSignatureIsWrong(): void
    {
        self::assertSame('no_matching_signature', self::unwrapReason('not json', self::headers(self::FLASHPI), self::FLASHPI['secret'], self::at(self::FLASHPI)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notObjects(): iterable
    {
        yield 'not JSON' => ['not json'];
        yield 'list' => ['[1, 2]'];
        yield 'empty list' => [' []'];
        yield 'text' => ['"text"'];
    }

    #[DataProvider('notObjects')]
    public function testThrowsInvalidPayloadForASignedBodyThatIsNotAJsonObject(string $body): void
    {
        $key = (string) base64_decode(substr(self::STANDARD['secret'], 6), true);
        $headers = self::headers(self::STANDARD, self::sign($key, self::STANDARD, $body));
        self::assertSame('invalid_payload', self::unwrapReason($body, $headers, self::STANDARD['secret'], self::at(self::STANDARD)));
    }

    public function testReturnsAnEmptyObject(): void
    {
        $key = (string) base64_decode(substr(self::STANDARD['secret'], 6), true);
        $event = (new Webhooks())->unwrap(" {}\n", self::headers(self::STANDARD, self::sign($key, self::STANDARD, " {}\n")), self::STANDARD['secret'], now: self::at(self::STANDARD));
        self::assertSame([], $event);
    }

    // Hardening.

    /**
     * @return iterable<string, array{array<string, string>, mixed, string}>
     */
    public static function failures(): iterable
    {
        yield 'bad signature' => [self::headers(self::FLASHPI, self::DECOY), self::FLASHPI['body'], 'no_matching_signature'];
        yield 'missing headers' => [['webhook-id' => self::FLASHPI['id']], self::FLASHPI['body'], 'missing_headers'];
        yield 'bad timestamp' => [[...self::headers(self::FLASHPI), 'webhook-timestamp' => 'x'], self::FLASHPI['body'], 'invalid_timestamp'];
        yield 'stale' => [[...self::headers(self::FLASHPI), 'webhook-timestamp' => '1'], self::FLASHPI['body'], 'timestamp_out_of_range'];
        yield 'parsed body' => [self::headers(self::FLASHPI), ['parsed' => true], 'invalid_payload'];
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('failures')]
    public function testKeepsTheKeyAndTheExpectedSignatureOutOfTheTraceOfItsErrors(array $headers, mixed $payload, string $expected): void
    {
        $key = (string) base64_decode(substr(self::FLASHPI['secret'], 6), true);
        $signature = hash_hmac('sha256', self::FLASHPI['id'] . '.' . self::FLASHPI['timestamp'] . '.' . self::FLASHPI['body'], $key, true);
        $client = new Flashpi(Replies::TEST_KEY);
        $checks = [
            static fn() => Flashpi::unwrapWebhook($payload, $headers, self::FLASHPI['secret'], now: self::at(self::FLASHPI)), // @phpstan-ignore argument.type
            static fn() => Flashpi::verifyWebhookSignature($payload, $headers, self::FLASHPI['secret'], now: self::at(self::FLASHPI)), // @phpstan-ignore argument.type
            static fn() => $client->webhooks->unwrap($payload, $headers, self::FLASHPI['secret'], now: self::at(self::FLASHPI)), // @phpstan-ignore argument.type
        ];
        foreach ($checks as $check) {
            try {
                $check();
                self::fail('Verified');
            } catch (WebhookVerificationException $error) {
                self::assertSame($expected, $error->reason);
                self::assertSame([], Secrets::inException($error, $key));
                self::assertSame([], Secrets::inException($error, $signature));
                self::assertSame([], Secrets::inException($error, self::FLASHPI['secret']));
            }
        }
    }

    public function testKeepsAnIncompleteKeyOutOfTheTraceOfItsError(): void
    {
        $key = implode('', array_map('chr', range(0, 22)));
        try {
            Flashpi::unwrapWebhook(self::FLASHPI['body'], self::headers(self::FLASHPI), 'whsec_' . base64_encode($key), now: self::at(self::FLASHPI));
            self::fail('Verified');
        } catch (WebhookVerificationException $error) {
            self::assertSame('invalid_secret', $error->reason);
            self::assertSame([], Secrets::inException($error, $key));
            self::assertSame([], Secrets::inException($error, base64_encode($key)));
        }
    }

    /**
     * @param Vector $vector
     *
     * @return array<string, string>
     */
    private static function headers(array $vector, ?string $signature = null): array
    {
        return [
            'webhook-id' => $vector['id'],
            'webhook-timestamp' => $vector['timestamp'],
            'webhook-signature' => $signature ?? $vector['signature'],
        ];
    }

    /**
     * `now` for a vector: its timestamp, plus `$offset` seconds.
     *
     * @param Vector $vector
     */
    private static function at(array $vector, int $offset = 0): int
    {
        return (int) $vector['timestamp'] + $offset;
    }

    /**
     * @param Vector $vector
     */
    private static function sign(string $key, array $vector, string $body): string
    {
        return 'v1,' . base64_encode(hash_hmac('sha256', "{$vector['id']}.{$vector['timestamp']}.{$body}", $key, true));
    }

    /**
     * The reason verifySignature() gives, or `verified`.
     *
     * @param iterable<mixed, mixed> $headers
     */
    private static function reason(mixed $payload, iterable $headers, mixed $secret, int|float|null $tolerance = null, \DateTimeInterface|int|float|null $now = null): string
    {
        try {
            (new Webhooks())->verifySignature($payload, $headers, $secret, $tolerance, $now); // @phpstan-ignore argument.type, argument.type, argument.type

            return 'verified';
        } catch (WebhookVerificationException $error) {
            return $error->reason;
        }
    }

    /**
     * @param array<string, string> $headers
     */
    private static function unwrapReason(string $payload, array $headers, string $secret, int $now): string
    {
        try {
            (new Webhooks())->unwrap($payload, $headers, $secret, now: $now);

            return 'unwrapped';
        } catch (WebhookVerificationException $error) {
            return $error->reason;
        }
    }
}
