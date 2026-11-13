<?php

declare(strict_types=1);

namespace Flashpi\Internal;

use Flashpi\Exception\FlashpiException;

/**
 * Checks of the settings, shared by the client and every request. The messages never show a
 * header value or the API key, which may be secrets.
 *
 * @internal
 */
final class Validate
{
    /**
     * The longest timeout, in seconds: the Node SDK's limit (2147483647 ms), so every SDK accepts
     * the same values.
     */
    public const MAX_TIMEOUT = 2147483.647;

    /** A header name (RFC 9110 `token`). */
    private const TOKEN = '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/';

    /** Trimmed from both ends of a header value, as the Fetch standard's `Headers` does. */
    private const HTTP_WHITESPACE = " \t\r\n";

    /** Refused inside a header value: control characters other than the tab. */
    private const CONTROL = '/[\x00-\x08\x0a-\x1f\x7f]/';

    /**
     * Headers the HTTP client sets from the body: another value would frame the body wrongly.
     */
    private const FRAMING = ['content-length', 'transfer-encoding'];

    /** What an API key is made of: visible ASCII, without spaces. */
    private const API_KEY = '/^[\x21-\x7e]+\z/';

    /**
     * What JavaScript's `trim()` removes around a string, the Node SDK's key included: its white
     * space and line breaks, and the byte order mark (U+FEFF).
     */
    private const JS_SPACE = '[\x{9}-\x{d}\x{20}\x{a0}\x{1680}\x{2000}-\x{200a}\x{2028}\x{2029}\x{202f}\x{205f}\x{3000}\x{feff}]';

    private function __construct() {}

    /**
     * A timeout in seconds: more than 0 and at most MAX_TIMEOUT.
     */
    public static function timeout(string $name, mixed $value): float
    {
        if ((!\is_int($value) && !\is_float($value)) || is_nan((float) $value) || $value <= 0 || $value > self::MAX_TIMEOUT) {
            throw new FlashpiException("{$name} must be more than 0 and at most " . self::MAX_TIMEOUT . ' seconds');
        }

        return (float) $value;
    }

    /**
     * A number of retries: a whole number, 0 or more.
     */
    public static function retries(string $name, mixed $value): int
    {
        if (!\is_int($value) || $value < 0) {
            throw new FlashpiException("{$name} must be a whole number, 0 or more");
        }

        return $value;
    }

    /**
     * A header name: an RFC 9110 token.
     *
     * @throws \InvalidArgumentException
     */
    public static function headerName(mixed $name): string
    {
        if (!\is_string($name)) {
            throw new \InvalidArgumentException('Header names must be strings, got ' . get_debug_type($name));
        }
        if (preg_match(self::TOKEN, $name) !== 1) {
            throw new \InvalidArgumentException(self::quote($name) . ' is not a valid header name');
        }

        return $name;
    }

    /**
     * A header value without the spaces, tabs and line breaks around it, as the Node SDK's
     * `Headers` sends it.
     *
     * Checked before anything is sent, so a bad value is never retried as a network failure: a
     * line break or another control character other than a tab left inside it is refused. Other
     * bytes are sent as they are (UTF-8 text included).
     *
     * @throws \InvalidArgumentException
     */
    public static function headerValue(string $label, #[\SensitiveParameter] mixed $value): string
    {
        if (!\is_string($value)) {
            throw new \InvalidArgumentException("{$label} must be a string, got " . get_debug_type($value));
        }
        $trimmed = trim($value, self::HTTP_WHITESPACE);
        if (preg_match(self::CONTROL, $trimmed) === 1) {
            throw new \InvalidArgumentException("{$label} has a line break or another control character");
        }

        return $trimmed;
    }

    /**
     * The headers of an array, each name and value checked. Content-Length and
     * Transfer-Encoding are refused: the HTTP client sets them from the body.
     *
     * @return array<string, string>
     *
     * @throws \InvalidArgumentException
     */
    public static function headers(string $label, #[\SensitiveParameter] mixed $headers): array
    {
        if ($headers === null) {
            return [];
        }
        if (!\is_array($headers)) {
            throw new \InvalidArgumentException("{$label} must be an array of header names to values");
        }
        $checked = [];
        foreach ($headers as $name => $value) {
            $valid = self::headerName($name);
            if (\in_array(strtolower($valid), self::FRAMING, true)) {
                throw new \InvalidArgumentException(self::quote($valid) . ' is set by the SDK from the body: leave it out');
            }
            $checked[$valid] = self::headerValue('The value of header ' . self::quote($valid), $value);
        }

        return $checked;
    }

    /**
     * The API key: the argument or FLASHPI_API_KEY, trimmed as JavaScript's `trim()`, the byte
     * order mark included. A key that cannot be used throws a FlashpiException that never shows
     * it.
     *
     * @throws FlashpiException
     */
    public static function apiKey(#[\SensitiveParameter] ?string $apiKey): string
    {
        $key = self::jsTrim($apiKey ?? self::environmentKey() ?? '');
        if ($key === '') {
            throw new FlashpiException('Missing API key: pass it to new Flashpi(apiKey: ...) or set the FLASHPI_API_KEY environment variable');
        }
        if (str_starts_with($key, 'whsec_')) {
            throw new FlashpiException('That is a webhook signing secret (whsec_...), not an API key: use it with Flashpi::unwrapWebhook() or $flashpi->webhooks');
        }
        if (!str_starts_with($key, 'fp_test_') && !str_starts_with($key, 'fp_live_')) {
            throw new FlashpiException('The API key must start with fp_test_ or fp_live_');
        }
        if (preg_match(self::API_KEY, $key) !== 1) {
            throw new FlashpiException('The API key has spaces, line breaks or other characters an API key cannot have: copy it again from the Flashpi console');
        }

        return $key;
    }

    /**
     * An http:// or https:// URL with a host.
     *
     * @throws FlashpiException
     */
    public static function baseUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        $bracketed = str_starts_with($host, '[') || str_ends_with($host, ']');
        if (
            $parts === false
            || !\in_array($scheme, ['http', 'https'], true)
            || $host === ''
            || ($bracketed && filter_var(trim($host, '[]'), \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6) === false)
        ) {
            throw new FlashpiException('baseUrl must be an http:// or https:// URL, got ' . self::quote($url));
        }

        return $url;
    }

    /**
     * A string as PHP code would quote it, with control characters escaped: for messages.
     */
    public static function quote(string $value): string
    {
        return "'" . addcslashes($value, "\0..\37\177\\'") . "'";
    }

    /**
     * FLASHPI_API_KEY from $_ENV, $_SERVER or the process environment, the first that has it.
     */
    private static function environmentKey(): ?string
    {
        foreach ([$_ENV['FLASHPI_API_KEY'] ?? null, $_SERVER['FLASHPI_API_KEY'] ?? null, getenv('FLASHPI_API_KEY')] as $value) {
            if (\is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function jsTrim(#[\SensitiveParameter] string $value): string
    {
        $trimmed = preg_replace('/\A' . self::JS_SPACE . '+|' . self::JS_SPACE . '+\z/u', '', $value);

        // Not UTF-8: only ASCII white space can be trimmed (the key is refused anyway).
        return $trimmed ?? trim($value, " \t\n\r\v\f");
    }
}
