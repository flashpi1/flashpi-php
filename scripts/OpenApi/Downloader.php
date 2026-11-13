<?php

declare(strict_types=1);

namespace Flashpi\Scripts\OpenApi;

/**
 * Fetches the Flashpi API contract.
 *
 * The source is the public document the docs publish, because the API repository is private.
 * FLASHPI_OPENAPI_URL overrides it, and FLASHPI_OPENAPI_FILE points at a local file instead (for
 * example a flashpi-api checkout). Only HTTPS is used: a URL or a redirect to anything else is
 * refused before it is requested, and redirects are followed by hand, at most five.
 */
final class Downloader
{
    public const DEFAULT_URL = 'https://flashpi.com.ec/docs/openapi.json';

    public const MAX_REDIRECTS = 5;

    private const REDIRECTS = [301, 302, 303, 307, 308];

    /** @var \Closure(string): Answer */
    private readonly \Closure $fetch;

    /**
     * @param array<string, string> $environment The environment variables (getenv()).
     * @param (\Closure(string): Answer)|null $fetch Sends one GET without following redirects
     *                                               (default: PHP's HTTP stream wrapper).
     */
    public function __construct(private readonly array $environment, ?\Closure $fetch = null)
    {
        $this->fetch = $fetch ?? self::fetch(...);
    }

    /**
     * Where the contract came from, and its text.
     *
     * @return array{string, string}
     */
    public function download(): array
    {
        $file = $this->environment['FLASHPI_OPENAPI_FILE'] ?? '';
        if ($file !== '') {
            $contents = @file_get_contents($file);
            if ($contents === false) {
                throw new \RuntimeException("Could not read {$file}");
            }

            return [$file, $contents];
        }
        $url = $this->environment['FLASHPI_OPENAPI_URL'] ?? '';
        $url = $url !== '' ? $url : self::DEFAULT_URL;
        if (!self::isHttps($url)) {
            throw new \RuntimeException("Refusing to download the contract over a non-HTTPS URL: {$url}");
        }
        $current = $url;
        for ($redirects = 0; ; $redirects++) {
            $answer = ($this->fetch)($current);
            if (!\in_array($answer->status, self::REDIRECTS, true)) {
                break;
            }
            if ($answer->location === null) {
                throw new \RuntimeException("{$current} answered {$answer->status} without a Location");
            }
            if ($redirects >= self::MAX_REDIRECTS) {
                throw new \RuntimeException("Too many redirects from {$url}");
            }
            $next = self::resolve($current, $answer->location);
            if (!self::isHttps($next)) {
                throw new \RuntimeException("Refusing a redirect to a non-HTTPS URL: {$next}");
            }
            $current = $next;
        }
        if ($answer->status < 200 || $answer->status >= 300) {
            throw new \RuntimeException("{$current} answered {$answer->status}");
        }

        return [$url, $answer->body];
    }

    /**
     * One GET through PHP's HTTP stream wrapper, which follows no redirect here.
     */
    public static function fetch(string $url): Answer
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Accept: application/json\r\nUser-Agent: flashpi-php-openapi-sync\r\nConnection: close\r\n",
                'follow_location' => 0,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
                'timeout' => 60,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            throw new \RuntimeException("Could not download {$url}");
        }
        try {
            $meta = stream_get_meta_data($stream);
            $body = stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
        $headers = [];
        $lines = $meta['wrapper_data'];
        foreach (\is_array($lines) ? $lines : [] as $line) {
            if (\is_string($line)) {
                $headers[] = $line;
            }
        }

        return Answer::fromHeaders($headers, $body === false ? '' : $body);
    }

    public static function isHttps(string $url): bool
    {
        return strtolower((string) parse_url($url, \PHP_URL_SCHEME)) === 'https';
    }

    /**
     * The absolute URL of a Location, relative to the URL that answered it.
     */
    public static function resolve(string $base, string $location): string
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        if (str_starts_with($location, '//')) {
            return "{$scheme}:{$location}";
        }
        $origin = $scheme . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = (string) ($parts['path'] ?? '/');
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($directory === '' ? '/' : $directory) . $location;
    }
}
