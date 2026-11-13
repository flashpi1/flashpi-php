<?php

declare(strict_types=1);

namespace Flashpi\Internal;

use Flashpi\Exception\FlashpiException;

/**
 * Paths of the API built from templates with ids.
 *
 * @internal
 */
final class Path
{
    private function __construct() {}

    /**
     * A path from a template with `{}` placeholders, each id percent-encoded as JavaScript's
     * encodeURIComponent() does: `Path::of('/v1/issuers/{}', $issuerId)`.
     *
     * Refuses empty ids, and `.` and `..`, which URL normalization would turn into the parent path
     * (another resource).
     *
     * @throws FlashpiException
     * @throws \InvalidArgumentException When the ids do not match the placeholders.
     */
    public static function of(string $template, string ...$ids): string
    {
        $parts = explode('{}', $template);
        if (\count($parts) !== \count($ids) + 1) {
            throw new \InvalidArgumentException(\sprintf('%s has %d placeholders, not %d', $template, \count($parts) - 1, \count($ids)));
        }
        $shown = str_replace('{}', '{id}', $template);
        $path = $parts[0];
        foreach (array_values($ids) as $index => $id) {
            if (trim($id, " \t\n\r\0\x0B") === '') {
                throw new FlashpiException("Missing id in {$shown}: got " . Validate::quote($id));
            }
            if ($id === '.' || $id === '..') {
                throw new FlashpiException("Invalid id in {$shown}: " . Validate::quote($id));
            }
            $path .= self::encode($id) . ($parts[$index + 1] ?? '');
        }

        return $path;
    }

    /**
     * Percent-encodes everything but what encodeURIComponent() leaves: A-Z a-z 0-9 - _ . ! ~ * ' ( ).
     */
    public static function encode(string $value): string
    {
        return strtr(rawurlencode($value), ['%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')']);
    }
}
