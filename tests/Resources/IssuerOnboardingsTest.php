<?php

declare(strict_types=1);

namespace Flashpi\Tests\Resources;

use Flashpi\Exception\FlashpiException;
use Flashpi\Tests\Support\Forms;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `$flashpi->issuerOnboardings` (Python: tests/test_resources.py's cases and
 * TestIssuerOnboardings). The upload rules are in CertificateUploadsTest.
 */
final class IssuerOnboardingsTest extends TestCase
{
    private const ONBOARDING = 'onb_1';

    private const P12 = "\x30\x82\x01\x02";

    public function testStartsAnOnboardingWithAStreamAndAFileName(): void
    {
        $api = self::api([Replies::json(['id' => self::ONBOARDING], 202), Replies::json(['id' => self::ONBOARDING], 202)]);
        $onboarding = $api->client->issuerOnboardings->create(['file' => Utils::streamFor(self::P12), 'password' => 'x', 'filename' => 'firma.p12']);
        self::assertSame(self::ONBOARDING, $onboarding['id']);
        $api->client->issuerOnboardings->create(['file' => self::P12, 'password' => 'x']);
        self::assertSame(['/v1/issuer-onboardings', '/v1/issuer-onboardings'], array_map(static fn($request): string => $request->getUri()->getPath(), $api->sent));
        self::assertSame(['POST', 'POST'], array_map(static fn($request): string => $request->getMethod(), $api->sent));
        self::assertSame([true, true], array_map(static fn($request): bool => $request->hasHeader('idempotency-key'), $api->sent));
        self::assertSame([['password', null, null, 'x'], ['file', 'firma.p12', 'application/x-pkcs12', self::P12]], Forms::upload($api->request(0)));
        self::assertSame(['file', 'certificate.p12', 'application/x-pkcs12', self::P12], Forms::upload($api->request(1))[1] ?? null);
    }

    public function testRefusesAMissingPasswordBeforeSending(): void
    {
        $api = self::api();
        self::throws(FlashpiException::class, static fn() => $api->op('issuerOnboardings.create', ['file' => self::P12]), '/password/');
        self::assertSame([], $api->sent);
    }

    public function testGetsAnOnboarding(): void
    {
        $api = self::api([Replies::json(['id' => self::ONBOARDING, 'status' => 'verifying'])]);
        self::assertSame('verifying', $api->client->issuerOnboardings->get(self::ONBOARDING)['status']);
        self::assertSentOnly($api, 'GET', '/v1/issuer-onboardings/' . self::ONBOARDING);
    }

    public function testCompletesAnOnboardingWithAnEmptyBodyByDefault(): void
    {
        $api = self::api([Replies::json(['id' => self::ONBOARDING], 201)]);
        $api->client->issuerOnboardings->complete(self::ONBOARDING);
        self::assertSentOnly($api, 'POST', '/v1/issuer-onboardings/' . self::ONBOARDING . '/complete', json: '{}');
    }

    public function testCompletesAnOnboardingWithItsBody(): void
    {
        $api = self::api([Replies::json(['id' => self::ONBOARDING], 201)]);
        $api->client->issuerOnboardings->complete(self::ONBOARDING, ['ruc' => '1790012345001']);
        self::assertSentOnly($api, 'POST', '/v1/issuer-onboardings/' . self::ONBOARDING . '/complete', json: '{"ruc":"1790012345001"}');
    }

    /**
     * @return iterable<string, array{string, list<mixed>}>
     */
    public static function methods(): iterable
    {
        yield 'create' => ['create', [['file' => self::P12, 'password' => 'x']]];
        yield 'get' => ['get', [self::ONBOARDING]];
        yield 'complete' => ['complete', [self::ONBOARDING]];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('methods')]
    public function testReturnsTheResponseWithWithResponse(string $method, array $arguments): void
    {
        $api = self::api([Replies::json(['id' => self::ONBOARDING], 201)]);
        $result = $api->opWithResponse("issuerOnboardings.{$method}", ...$arguments);
        self::assertSame([['id' => self::ONBOARDING], 201], [$result->data, $result->response->getStatusCode()]);
    }
}
