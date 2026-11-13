<?php

declare(strict_types=1);

namespace Flashpi\Tests\Resources;

use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\UnprocessableEntityException;
use Flashpi\Tests\Support\CountingReader;
use Flashpi\Tests\Support\Forms;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\Secrets;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * .p12 uploads: `$flashpi->issuers->certificates->upload()` and
 * `$flashpi->issuerOnboardings->create()` (Python: tests/test_resources.py's
 * TestCertificateUploads and TestTheFramesOfAnUploadError).
 */
final class CertificateUploadsTest extends TestCase
{
    private const ISSUER = 'iss_1';

    private const CERTIFICATE = 'cert_1';

    /** The start of a .p12 (a DER sequence): enough for the SDK, which never parses it. */
    private const P12 = "\x30\x82\x01\x02";

    private const MIB = 1_048_576;

    private const SECRET_P12 = "0\x82PRIVATE-KEY-OF-THE-ISSUER";

    private const PASSWORD = 'Passw0rd-of-the-p12';

    /** @var list<resource> */
    private array $handles = [];

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->handles as $handle) {
            if (\is_resource($handle)) {
                fclose($handle);
            }
        }
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function testUploadsACertificateAsMultipartWithTheFileAndThePassword(): void
    {
        $api = self::api([Replies::json(['id' => self::CERTIFICATE, 'status' => 'processing'], 202)]);
        $certificate = $api->client->issuers->certificates->upload(self::ISSUER, ['file' => self::P12, 'password' => 'secret ñ']);
        self::assertSame(self::CERTIFICATE, $certificate['id']);
        $request = $api->request(0);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/v1/issuers/' . self::ISSUER . '/certificates', $request->getUri()->getPath());
        self::assertTrue($request->hasHeader('idempotency-key'));
        self::assertSame(
            [['password', null, null, 'secret ñ'], ['file', 'certificate.p12', 'application/x-pkcs12', self::P12]],
            Forms::upload($request),
        );
    }

    public function testReturnsTheResponseWithWithResponse(): void
    {
        $api = self::api([Replies::json(['id' => self::CERTIFICATE, 'status' => 'processing'], 202, ['X-Request-Id' => 'req_upload'])]);
        $result = $api->client->issuers->certificates->withResponse()->upload(self::ISSUER, ['file' => self::P12, 'password' => 'x']);
        self::assertSame([self::CERTIFICATE, 202, 'req_upload'], [$result->data['id'], $result->response->getStatusCode(), $result->requestId]);
        self::assertSame(self::P12, Forms::upload($api->request(0))[1][3] ?? null);
    }

    /**
     * @return iterable<string, array{\Closure(self): mixed}>
     */
    public static function files(): iterable
    {
        yield 'string' => [static fn(): string => self::P12];
        yield 'stream' => [static fn(self $test): mixed => $test->memory(self::P12)];
        yield 'stream opened with w+' => [static fn(self $test): mixed => $test->memory(self::P12, 'w+b')];
        yield 'PSR-7 stream' => [static fn(): mixed => Utils::streamFor(self::P12)];
    }

    /**
     * @param \Closure(self): mixed $file
     */
    #[DataProvider('files')]
    public function testTakesItsContentsAStreamOrAPsr7Stream(\Closure $file): void
    {
        $api = self::api([Replies::json(['id' => self::CERTIFICATE], 202)]);
        $api->op('issuers.certificates.upload', self::ISSUER, ['file' => $file($this), 'password' => 'x', 'filename' => 'firma.p12']);
        self::assertSame(['file', 'firma.p12', 'application/x-pkcs12', self::P12], Forms::upload($api->request(0))[1] ?? null);
    }

    public function testReadsAFileOpenedFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'p12');
        self::assertIsString($path);
        try {
            file_put_contents($path, self::P12);
            $file = fopen($path, 'rb');
            self::assertIsResource($file);
            $this->handles[] = $file;
            $api = self::api([Replies::json(['id' => self::CERTIFICATE], 202)]);
            $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $file, 'password' => 'x']);
            self::assertSame(self::P12, Forms::upload($api->request(0))[1][3] ?? null);
        } finally {
            unlink($path);
        }
    }

    public function testReadsAStreamFromWhereItStands(): void
    {
        $file = $this->memory('ignored' . self::P12);
        fseek($file, 7);
        $api = self::api([Replies::json(['id' => self::CERTIFICATE], 202)]);
        $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $file, 'password' => 'x']);
        self::assertSame(self::P12, Forms::upload($api->request(0))[1][3] ?? null);
    }

    public function testSendsTheSameFileAgainOnARetry(): void
    {
        $api = self::api([Replies::problem(500, 'internal_error'), Replies::json(['id' => self::CERTIFICATE], 202)]);
        $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $this->memory(self::P12), 'password' => 'x']);
        self::assertCount(2, $api->sent);
        self::assertSame($api->request(0)->getHeaderLine('idempotency-key'), $api->request(1)->getHeaderLine('idempotency-key'));
        self::assertSame([self::P12, self::P12], array_map(static fn($request): ?string => Forms::upload($request)[1][3] ?? null, $api->sent));
    }

    public function testAcceptsAFileOfExactly1Mib(): void
    {
        $api = self::api([Replies::json(['id' => self::CERTIFICATE], 202), Replies::json(['id' => self::CERTIFICATE], 202)]);
        $api->client->issuers->certificates->upload(self::ISSUER, ['file' => str_repeat("\0", self::MIB), 'password' => 'x']);
        $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $this->memory(str_repeat("\0", self::MIB)), 'password' => 'x']);
        self::assertSame([self::MIB, self::MIB], array_map(static fn($request): int => \strlen(Forms::upload($request)[1][3] ?? ''), $api->sent));
    }

    public function testReadsNoMoreThanOneBytePastTheLimitOfABigFile(): void
    {
        $file = new CountingReader(str_repeat("\0", 50 * self::MIB));
        $api = self::api();
        self::throws(
            FlashpiException::class,
            static fn() => $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $file->stream(), 'password' => 'x']),
            '/^The certificate file has more than 1048576 bytes, the most the API accepts$/',
        );
        self::assertSame([self::MIB + 1], $file->asked);
        self::assertSame(self::MIB + 1, $file->given);
        self::assertSame([], $api->sent);

        $big = $this->memory(str_repeat("\0", 2 * self::MIB));
        self::throws(FlashpiException::class, static fn() => $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $big, 'password' => 'x']), '/more than 1048576 bytes/');
        self::assertSame(self::MIB + 1, ftell($big));
    }

    public function testReadsAStreamThatReturnsFewerBytesThanAskedToItsEnd(): void
    {
        $file = new CountingReader(str_repeat(self::P12, 100), piece: 7);
        $api = self::api([Replies::json(['id' => self::CERTIFICATE], 202)]);
        $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $file->stream(), 'password' => 'x']);
        self::assertSame(str_repeat(self::P12, 100), Forms::upload($api->request(0))[1][3] ?? null);
        self::assertSame(self::MIB + 1, $file->asked[0] ?? null);
        self::assertSame(self::MIB + 1 - 7, $file->asked[1] ?? null);
    }

    public function testReadsASocketThatSendsItsFileInPieces(): void
    {
        [$reading, $writing] = $this->socketPair();
        fwrite($writing, self::P12);
        fclose($writing);
        $api = self::api([Replies::json(['id' => self::CERTIFICATE], 202)]);
        $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $reading, 'password' => 'x']);
        self::assertSame(self::P12, Forms::upload($api->request(0))[1][3] ?? null);
    }

    /**
     * @return iterable<string, array{bool, string}>
     */
    public static function nonBlocking(): iterable
    {
        yield 'empty stream' => [false, ''];
        yield 'partly read stream' => [false, self::P12];
        yield 'empty PSR-7 stream' => [true, ''];
        yield 'partly read PSR-7 stream' => [true, self::P12];
    }

    #[DataProvider('nonBlocking')]
    public function testRefusesANonBlockingStreamWithNoDataReady(bool $psr7, string $ready): void
    {
        [$reading, $writing] = $this->socketPair();
        stream_set_blocking($reading, false);
        fwrite($writing, $ready);
        $file = $psr7 ? new Stream($reading) : $reading;
        $api = self::api();
        self::throws(
            FlashpiException::class,
            static fn() => $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $file, 'password' => 'x']),
            '/non-blocking stream with no data ready/',
        );
        self::assertSame([], $api->sent);
    }

    public function testRefusesAStreamWhoseReadTimesOut(): void
    {
        [$reading] = $this->socketPair();
        stream_set_timeout($reading, 0, 1000);
        $api = self::api();
        self::throws(
            FlashpiException::class,
            static fn() => $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $reading, 'password' => 'x']),
            '/^The certificate file could not be read: its read timed out$/',
        );
        self::assertSame([], $api->sent);
    }

    public function testRefusesAStreamWhoseReadFails(): void
    {
        $directory = fopen(sys_get_temp_dir(), 'rb');
        self::assertIsResource($directory);
        $this->handles[] = $directory;
        $api = self::api();
        self::throws(
            FlashpiException::class,
            static fn() => $api->client->issuers->certificates->upload(self::ISSUER, ['file' => $directory, 'password' => 'x']),
            '/^The certificate file could not be read: fread\(\): .*Is a directory$/',
        );
        self::assertSame([], $api->sent);
    }

    public function testLetsThePsr7StreamsOwnErrorThrough(): void
    {
        $api = self::api();
        $error = self::throws(
            \RuntimeException::class,
            static fn() => $api->client->issuers->certificates->upload(self::ISSUER, ['file' => CountingReader::failing(), 'password' => self::PASSWORD]),
            '/^Stale file handle$/',
        );
        // The trace goes down to the stream's own read().
        $frame = $error->getTrace()[1] ?? [];
        self::assertSame(FnStream::class . '->read', ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? ''));
        self::assertSame([], Secrets::inException($error, self::PASSWORD));
        self::assertSame([], $api->sent);
    }

    /**
     * @return iterable<string, array{\Closure(self): array<mixed>, class-string<\Throwable>, string}>
     */
    public static function badUploads(): iterable
    {
        yield 'empty file' => [static fn(): array => ['file' => '', 'password' => 'x'], FlashpiException::class, '/^The certificate file is empty$/'];
        yield 'empty stream' => [static fn(self $test): array => ['file' => $test->memory(''), 'password' => 'x'], FlashpiException::class, '/^The certificate file is empty$/'];
        yield 'too big' => [static fn(): array => ['file' => str_repeat("\0", self::MIB + 1), 'password' => 'x'], FlashpiException::class, '/^The certificate file has 1048577 bytes; the API accepts up to 1048576$/'];
        yield 'no password' => [static fn(): array => ['file' => self::P12], FlashpiException::class, '/^The certificate password must be a string$/'];
        yield 'null password' => [static fn(): array => ['file' => self::P12, 'password' => null], FlashpiException::class, '/password must be a string/'];
        yield 'number password' => [static fn(): array => ['file' => self::P12, 'password' => 1234], FlashpiException::class, '/password must be a string/'];
        yield 'no file' => [static fn(): array => ['password' => 'x'], \InvalidArgumentException::class, '/^The certificate file must be its contents \(a string\), a stream opened for reading \(\'rb\'\) or a PSR-7 stream, got null$/'];
        yield 'number file' => [static fn(): array => ['file' => 1234, 'password' => 'x'], \InvalidArgumentException::class, '/got int$/'];
        yield 'object file' => [static fn(): array => ['file' => new \SplFileInfo('firma.p12'), 'password' => 'x'], \InvalidArgumentException::class, '/got SplFileInfo$/'];
        yield 'closed stream' => [static fn(self $test): array => ['file' => $test->closed(), 'password' => 'x'], \InvalidArgumentException::class, '/got resource \(closed\)$/'];
        yield 'stream for writing only' => [static fn(self $test): array => ['file' => $test->writeOnly(), 'password' => 'x'], \InvalidArgumentException::class, "/opened for writing only \\('wb'\\): open it with 'rb'$/"];
        yield 'PSR-7 stream that cannot be read' => [static fn(): array => ['file' => FnStream::decorate(Utils::streamFor('x'), ['isReadable' => static fn(): bool => false]), 'password' => 'x'], \InvalidArgumentException::class, '/^The certificate file is a PSR-7 stream that cannot be read$/'];
        yield 'number file name' => [static fn(): array => ['file' => self::P12, 'password' => 'x', 'filename' => 1], \InvalidArgumentException::class, '/^The certificate filename must be a string, got int$/'];
    }

    /**
     * @param \Closure(self): array<mixed> $upload
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('badUploads')]
    public function testRefusesABadUploadBeforeSending(\Closure $upload, string $class, string $pattern): void
    {
        $api = self::api();
        self::throws($class, fn() => $api->op('issuers.certificates.upload', self::ISSUER, $upload($this)), $pattern);
        self::assertSame([], $api->sent);
    }

    public function testRefusesAnUploadThatIsNotAnArray(): void
    {
        $api = self::api();
        self::throws(\TypeError::class, static fn() => $api->op('issuers.certificates.upload', self::ISSUER, self::P12));
        self::assertSame([], $api->sent);
    }

    /**
     * @return iterable<string, array{string, bool, \Closure(self): mixed, bool, class-string<\Throwable>}>
     */
    public static function failedUploads(): iterable
    {
        $files = [
            'answer' => [static fn(): string => self::SECRET_P12, true, UnprocessableEntityException::class],
            'stream answer' => [static fn(self $test): mixed => $test->memory(self::SECRET_P12), true, UnprocessableEntityException::class],
            'PSR-7 answer' => [static fn(): mixed => Utils::streamFor(self::SECRET_P12), true, UnprocessableEntityException::class],
            'too big' => [static fn(): string => str_repeat(self::SECRET_P12, 40_000), false, FlashpiException::class],
            'closed stream' => [static fn(self $test): mixed => $test->closed(), false, \InvalidArgumentException::class],
            'read fails' => [static fn(): mixed => CountingReader::failing(), false, \RuntimeException::class],
        ];
        foreach (self::uploadMethods() as $method) {
            foreach ([false, true] as $withResponse) {
                foreach ($files as $label => [$file, $answered, $class]) {
                    yield "{$method}, " . ($withResponse ? 'withResponse, ' : '') . $label => [$method, $withResponse, $file, $answered, $class];
                }
            }
        }
    }

    /**
     * Error trackers record the arguments of the frames an error passes through: none of the
     * SDK's shows the .p12 or its password, however the upload fails.
     *
     * @param \Closure(self): mixed $file
     * @param class-string<\Throwable> $class
     */
    #[DataProvider('failedUploads')]
    public function testNoFrameOfItsErrorShowsTheFileOrThePassword(string $method, bool $withResponse, \Closure $file, bool $answered, string $class): void
    {
        $api = self::api($answered ? [Replies::problem(422, 'invalid_certificate')] : []);
        $upload = ['file' => $file($this), 'password' => self::PASSWORD, 'filename' => 'firma.p12'];
        $arguments = $method === 'issuerOnboardings.create' ? [$upload] : [self::ISSUER, $upload];
        $error = self::throws($class, static fn() => $withResponse ? $api->opWithResponse($method, ...$arguments) : $api->op($method, ...$arguments));
        self::assertSame([], Secrets::inException($error, self::PASSWORD));
        self::assertSame([], Secrets::inException($error, self::SECRET_P12));
        self::assertCount($answered ? 1 : 0, $api->sent);
    }

    /**
     * The methods that upload a .p12.
     *
     * @return list<string>
     */
    private static function uploadMethods(): array
    {
        return ['issuers.certificates.upload', 'issuerOnboardings.create'];
    }

    /**
     * A stream in memory with `$content`, at its start.
     *
     * @return resource
     */
    private function memory(string $content, string $mode = 'w+b'): mixed
    {
        $file = fopen('php://memory', $mode);
        self::assertIsResource($file);
        fwrite($file, $content);
        rewind($file);
        $this->handles[] = $file;

        return $file;
    }

    /**
     * @return resource
     */
    private function closed(): mixed
    {
        $file = fopen('php://memory', 'rb');
        self::assertIsResource($file);
        fclose($file);

        return $file;
    }

    /**
     * @return resource
     */
    private function writeOnly(): mixed
    {
        $path = sys_get_temp_dir() . '/flashpi-write-only-' . getmypid();
        $this->paths[] = $path;
        $file = fopen($path, 'wb');
        self::assertIsResource($file);
        $this->handles[] = $file;

        return $file;
    }

    /**
     * Two connected sockets: what is written to the second is read from the first.
     *
     * @return array{resource, resource}
     */
    private function socketPair(): array
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        $reading = $pair[0] ?? null;
        $writing = $pair[1] ?? null;
        self::assertIsResource($reading);
        self::assertIsResource($writing);
        $this->handles[] = $reading;
        $this->handles[] = $writing;

        return [$reading, $writing];
    }
}
