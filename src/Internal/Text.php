<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * Text of the answers the SDK quotes in its messages.
 *
 * @internal
 */
final class Text
{
    private function __construct() {}

    /**
     * The text with its runs of white space made one space, cut to `$length` characters: what an
     * error message quotes of a body that is not Problem Details.
     */
    public static function excerpt(string $text, int $length): string
    {
        $text = self::scrub($text);
        $words = preg_split('/(*UCP)\s+/u', $text, -1, \PREG_SPLIT_NO_EMPTY);
        $joined = implode(' ', $words === false ? [$text] : $words);
        if (preg_match('/^.{0,' . $length . '}/us', $joined, $match) === 1) {
            return $match[0] ?? '';
        }

        return substr($joined, 0, $length);
    }

    /**
     * UTF-8 text with every byte that is not part of a valid sequence replaced by U+FFFD, as the
     * other SDKs decode an answer.
     */
    public static function scrub(string $text): string
    {
        if (preg_match('//u', $text) === 1) {
            return $text;
        }
        $valid = '[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}'
            . '|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}';

        return (string) preg_replace('/(?:' . $valid . ')(*SKIP)(*FAIL)|./s', "\u{FFFD}", $text);
    }
}
