<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * Reads a `Retry-After` header: delta-seconds or an HTTP date (an ISO 8601 date is accepted too).
 *
 * @internal
 */
final class RetryAfter
{
    /** Longer digit strings are past any useful wait (and past PHP's integers). */
    private const MAX_DIGITS = 18;

    /** The HTTP date formats (RFC 9110: IMF-fixdate, then the obsolete RFC 850 and asctime). */
    private const HTTP_DATES = [
        '!D, d M Y H:i:s \G\M\T',
        '!D, d M Y H:i:s O',
        '!d M Y H:i:s O',
        '!d M Y H:i:s \G\M\T',
        '!l, d-M-y H:i:s \G\M\T',
        '!D M j H:i:s Y',
    ];

    private const ISO_8601 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?\z/i';

    private function __construct() {}

    /**
     * Seconds to wait, or null when the value is missing or not one of the two forms.
     *
     * @param float|null $now The current Unix time in seconds (default: the clock).
     */
    public static function parse(?string $value, ?float $now = null): ?int
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value, " \t\r\n");
        if (preg_match('/^[0-9]+\z/', $trimmed) === 1) {
            return \strlen($trimmed) <= self::MAX_DIGITS ? (int) $trimmed : 10 ** self::MAX_DIGITS;
        }
        $moment = self::date($trimmed);
        if ($moment === null) {
            return null;
        }

        return max(0, (int) ceil($moment - ($now ?? microtime(true))));
    }

    /**
     * Unix seconds of an HTTP date or an ISO 8601 date (UTC when it names no zone), or null.
     */
    private static function date(string $value): ?float
    {
        $utc = new \DateTimeZone('UTC');
        foreach (self::HTTP_DATES as $format) {
            $parsed = \DateTimeImmutable::createFromFormat($format, $value, $utc);
            if ($parsed !== false && self::exact($parsed, $format, $value)) {
                return (float) $parsed->format('U.u');
            }
        }
        if (preg_match(self::ISO_8601, $value) !== 1) {
            return null;
        }
        try {
            return (float) (new \DateTimeImmutable($value, $utc))->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Whether a parsed date is the one written: createFromFormat() rolls invalid dates over (a
     * 31 November becomes 1 December) and reports it only as a warning.
     */
    private static function exact(\DateTimeImmutable $parsed, string $format, string $value): bool
    {
        $errors = \DateTimeImmutable::getLastErrors();

        return ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $parsed->format(ltrim($format, '!')) === $value;
    }
}
