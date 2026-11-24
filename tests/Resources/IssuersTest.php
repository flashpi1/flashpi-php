<?php

declare(strict_types=1);

namespace Flashpi\Tests\Resources;

use Flashpi\ApiResponse;
use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\PermissionDeniedException;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `$flashpi->issuers`, with its establishments, emission points and certificates (Python:
 * tests/test_resources.py's cases and TestIssuers). The uploads are in CertificateUploadsTest.
 */
final class IssuersTest extends TestCase
{
    private const ISSUER = 'iss_1';

    private const ESTABLISHMENT = 'est_1';

    private const POINT = 'pt_1';

    private const CERTIFICATE = 'cert_1';

    private const P12 = "\x30\x82\x01\x02";

    /**
     * Each method, its arguments, the request it sends (method, path, JSON body) and its answer.
     *
     * @return iterable<string, array{string, list<mixed>, string, string, string|null, Response}>
     */
    public static function operations(): iterable
    {
        $issuer = '/v1/issuers/' . self::ISSUER;
        $establishment = "{$issuer}/establishments/" . self::ESTABLISHMENT;
        $point = "{$establishment}/emission-points/" . self::POINT;
        yield 'issuers.create' => ['issuers.create', [['ruc' => '1']], 'POST', '/v1/issuers', '{"ruc":"1"}', Replies::json([], 201)];
        yield 'issuers.list' => ['issuers.list', [], 'GET', '/v1/issuers', null, Replies::page([])];
        yield 'issuers.get' => ['issuers.get', [self::ISSUER], 'GET', $issuer, null, Replies::json([])];
        yield 'issuers.update' => ['issuers.update', [self::ISSUER, ['tradeName' => 'X']], 'PATCH', $issuer, '{"tradeName":"X"}', Replies::json([])];
        yield 'issuers.delete' => ['issuers.delete', [self::ISSUER], 'DELETE', $issuer, null, new Response(204)];
        yield 'issuers.establishments.create' => ['issuers.establishments.create', [self::ISSUER, ['code' => '002']], 'POST', "{$issuer}/establishments", '{"code":"002"}', Replies::json([], 201)];
        yield 'issuers.establishments.list' => ['issuers.establishments.list', [self::ISSUER], 'GET', "{$issuer}/establishments", null, Replies::page([])];
        yield 'issuers.establishments.get' => ['issuers.establishments.get', [self::ISSUER, self::ESTABLISHMENT], 'GET', $establishment, null, Replies::json([])];
        yield 'issuers.establishments.update' => ['issuers.establishments.update', [self::ISSUER, self::ESTABLISHMENT, ['address' => 'Quito']], 'PATCH', $establishment, '{"address":"Quito"}', Replies::json([])];
        yield 'issuers.establishments.delete' => ['issuers.establishments.delete', [self::ISSUER, self::ESTABLISHMENT], 'DELETE', $establishment, null, new Response(204)];
        yield 'issuers.establishments.emissionPoints.create' => ['issuers.establishments.emissionPoints.create', [self::ISSUER, self::ESTABLISHMENT, ['code' => '002']], 'POST', "{$establishment}/emission-points", '{"code":"002"}', Replies::json([], 201)];
        yield 'issuers.establishments.emissionPoints.list' => ['issuers.establishments.emissionPoints.list', [self::ISSUER, self::ESTABLISHMENT], 'GET', "{$establishment}/emission-points", null, Replies::page([])];
        yield 'issuers.establishments.emissionPoints.get' => ['issuers.establishments.emissionPoints.get', [self::ISSUER, self::ESTABLISHMENT, self::POINT], 'GET', $point, null, Replies::json([])];
        yield 'issuers.establishments.emissionPoints.delete' => ['issuers.establishments.emissionPoints.delete', [self::ISSUER, self::ESTABLISHMENT, self::POINT], 'DELETE', $point, null, new Response(204)];
        yield 'issuers.certificates.list' => ['issuers.certificates.list', [self::ISSUER], 'GET', "{$issuer}/certificates", null, Replies::page([])];
        yield 'issuers.certificates.get' => ['issuers.certificates.get', [self::ISSUER, self::CERTIFICATE], 'GET', "{$issuer}/certificates/" . self::CERTIFICATE, null, Replies::json([])];
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('operations')]
    public function testSendsItsOperation(string $name, array $arguments, string $method, string $path, ?string $json, Response $reply): void
    {
        $api = self::api([$reply]);
        $result = $api->op($name, ...$arguments);
        self::assertSentOnly($api, $method, $path, json: $json);
        if ($method === 'DELETE') {
            self::assertNull($result);
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    #[DataProvider('operations')]
    public function testReturnsTheResponseWithWithResponse(string $name, array $arguments, string $method, string $path, ?string $json, Response $reply): void
    {
        $api = self::api([$reply]);
        $result = $api->opWithResponse($name, ...$arguments);
        self::assertInstanceOf(ApiResponse::class, $result);
        self::assertSame($reply->getStatusCode(), $result->response->getStatusCode());
        self::assertSame($path, $api->request(0)->getUri()->getPath());
        self::assertSame($method === 'DELETE' ? null : 'req_123', $result->requestId);
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function lists(): iterable
    {
        yield 'issuers' => ['issuers.list', []];
        yield 'establishments' => ['issuers.establishments.list', [self::ISSUER]];
        yield 'emission points' => ['issuers.establishments.emissionPoints.list', [self::ISSUER, self::ESTABLISHMENT]];
        yield 'certificates' => ['issuers.certificates.list', [self::ISSUER]];
    }

    /**
     * @param list<string> $arguments
     */
    #[DataProvider('lists')]
    public function testListsWithPaging(string $name, array $arguments): void
    {
        $api = self::api([Replies::page([['id' => 'a']], 'next'), Replies::page([['id' => 'b']])]);
        $first = $api->op($name, ...[...$arguments, ['limit' => 1]]);
        self::assertIsIterable($first);
        $ids = [];
        foreach ($first as $item) {
            self::assertIsArray($item);
            $ids[] = $item['id'] ?? null;
        }
        self::assertSame(['a', 'b'], $ids);
        self::assertSame([['limit' => '1'], ['limit' => '1', 'cursor' => 'next']], self::queries($api));
    }

    public function testReachesTheNestedResourcesOfAnIssuer(): void
    {
        $api = self::api([Replies::json(['id' => self::POINT, 'code' => '001'])]);
        $point = $api->client->issuers->establishments->emissionPoints->get(self::ISSUER, self::ESTABLISHMENT, self::POINT);
        self::assertSame('001', $point['code']);
        self::assertSame([], $api->client->issuers->__debugInfo());
    }

    public function testReadsTheWarningsOfAnAcceptedCertificate(): void
    {
        $warning = ['code' => 'ruc_mismatch', 'message' => "This certificate is not for the issuer's RUC: it signs test documents only."];
        $api = self::api([Replies::json(['id' => self::CERTIFICATE, 'status' => 'accepted', 'warnings' => [$warning]])]);
        $certificate = $api->client->issuers->certificates->get(self::ISSUER, self::CERTIFICATE);
        self::assertSame([$warning], $certificate['warnings']);
        self::assertSame(['ruc_mismatch'], array_column($certificate['warnings'], 'code'));
    }

    public function testRaisesLiveKeyRequiredAsPermissionDenied(): void
    {
        $api = self::api([Replies::problem(403, 'live_key_required')]);
        $error = self::throws(PermissionDeniedException::class, static fn() => $api->client->issuers->update(self::ISSUER, ['tradeName' => 'X']));
        self::assertSame('live_key_required', $error->errorCode);
        self::assertSame(403, $error->status);
        self::assertCount(1, $api->sent);
    }

    public function testRefusesAMissingIdBeforeSendingAnything(): void
    {
        $api = self::api();
        self::throws(FlashpiException::class, static fn() => $api->client->issuers->get(''), '/^Missing id in \/v1\/issuers\/\{id\}: got \'\'$/');
        self::throws(FlashpiException::class, static fn() => $api->client->issuers->certificates->upload(' ', ['file' => self::P12, 'password' => 'x']), '/certificates/');
        self::assertSame([], $api->sent);
    }
}
