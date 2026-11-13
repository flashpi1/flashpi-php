<?php

declare(strict_types=1);

namespace Flashpi\Tests\Scripts;

use Flashpi\Scripts\OpenApi\Answer;
use Flashpi\Scripts\OpenApi\Downloader;
use PHPUnit\Framework\TestCase;

/**
 * The download of the contract: HTTPS only, redirects followed by hand.
 */
final class DownloaderTest extends TestCase
{
    /** @var list<string> */
    private array $requested = [];

    public function testDownloadsTheDefaultUrl(): void
    {
        $downloader = new Downloader([], $this->answers(new Answer(200, null, '{"openapi":"3.1.0"}')));
        self::assertSame([Downloader::DEFAULT_URL, '{"openapi":"3.1.0"}'], $downloader->download());
        self::assertSame([Downloader::DEFAULT_URL], $this->requested);
    }

    public function testReadsALocalFileInstead(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'contract');
        self::assertIsString($file);
        file_put_contents($file, '{"local":true}');
        $downloader = new Downloader(['FLASHPI_OPENAPI_FILE' => $file], $this->answers());
        self::assertSame([$file, '{"local":true}'], $downloader->download());
        unlink($file);
        self::assertSame([], $this->requested);

        $this->expectExceptionMessage('Could not read /nowhere/openapi.json');
        (new Downloader(['FLASHPI_OPENAPI_FILE' => '/nowhere/openapi.json'], $this->answers()))->download();
    }

    public function testRefusesAPlainHttpUrl(): void
    {
        $downloader = new Downloader(['FLASHPI_OPENAPI_URL' => 'http://docs.example/openapi.json'], $this->answers());
        $this->expectExceptionMessage('Refusing to download the contract over a non-HTTPS URL: http://docs.example/openapi.json');
        try {
            $downloader->download();
        } finally {
            self::assertSame([], $this->requested);
        }
    }

    public function testRefusesARedirectToPlainHttpBeforeRequestingIt(): void
    {
        $downloader = new Downloader(
            ['FLASHPI_OPENAPI_URL' => 'https://docs.example/a'],
            $this->answers(new Answer(302, 'http://docs.example/b', '')),
        );
        try {
            $downloader->download();
            self::fail('The redirect was followed');
        } catch (\RuntimeException $error) {
            self::assertSame('Refusing a redirect to a non-HTTPS URL: http://docs.example/b', $error->getMessage());
        }
        self::assertSame(['https://docs.example/a'], $this->requested);
    }

    public function testFollowsRedirectsToHttpsUrls(): void
    {
        $downloader = new Downloader(['FLASHPI_OPENAPI_URL' => 'https://docs.example/docs/openapi.json'], $this->answers(
            new Answer(301, '/v2/openapi.json', ''),
            new Answer(307, 'openapi-latest.json', ''),
            new Answer(308, '//cdn.example/openapi.json', ''),
            new Answer(302, 'HTTPS://other.example:8443/x.json', ''),
            new Answer(200, null, '{}'),
        ));
        self::assertSame(['https://docs.example/docs/openapi.json', '{}'], $downloader->download());
        self::assertSame([
            'https://docs.example/docs/openapi.json',
            'https://docs.example/v2/openapi.json',
            'https://docs.example/v2/openapi-latest.json',
            'https://cdn.example/openapi.json',
            'HTTPS://other.example:8443/x.json',
        ], $this->requested);
    }

    public function testStopsAfterFiveRedirects(): void
    {
        $answers = array_fill(0, 6, new Answer(302, 'https://docs.example/again', ''));
        $downloader = new Downloader([], $this->answers(...$answers));
        $this->expectExceptionMessage('Too many redirects from ' . Downloader::DEFAULT_URL);
        $downloader->download();
    }

    public function testRefusesARedirectWithoutALocationAndAnErrorStatus(): void
    {
        try {
            (new Downloader([], $this->answers(new Answer(302, null, ''))))->download();
            self::fail('A redirect without a Location was accepted');
        } catch (\RuntimeException $error) {
            self::assertSame(Downloader::DEFAULT_URL . ' answered 302 without a Location', $error->getMessage());
        }
        $this->expectExceptionMessage(Downloader::DEFAULT_URL . ' answered 404');
        (new Downloader([], $this->answers(new Answer(404, null, 'Not found'))))->download();
    }

    public function testResolvesRelativeLocations(): void
    {
        self::assertSame('https://h/y', Downloader::resolve('https://h', 'y'));
        self::assertSame('https://h:1/a/y', Downloader::resolve('https://h:1/a/b', 'y'));
        self::assertTrue(Downloader::isHttps('HTTPS://h'));
        self::assertFalse(Downloader::isHttps('file:///etc/passwd'));
    }

    public function testReadsTheStatusAndLocationOfTheLastAnswer(): void
    {
        $answer = Answer::fromHeaders(
            ['HTTP/1.1 100 Continue', 'Location: /ignored', 'HTTP/1.1 302 Found', 'location:  https://h/x ', 'X-Other: 1'],
            'body',
        );
        self::assertSame([302, 'https://h/x', 'body'], [$answer->status, $answer->location, $answer->body]);
        $final = Answer::fromHeaders(['HTTP/1.1 302 Found', 'Location: /x', 'HTTP/2 200'], '');
        self::assertSame([200, null], [$final->status, $final->location]);
    }

    /**
     * A fetch that answers from a queue and records the URLs it was asked for.
     *
     * @return \Closure(string): Answer
     */
    private function answers(Answer ...$answers): \Closure
    {
        return function (string $url) use (&$answers): Answer {
            $this->requested[] = $url;
            $answer = array_shift($answers);
            self::assertNotNull($answer, "Unexpected request to {$url}");

            return $answer;
        };
    }
}
