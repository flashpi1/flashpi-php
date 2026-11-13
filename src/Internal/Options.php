<?php

declare(strict_types=1);

namespace Flashpi\Internal;

use Flashpi\Exception\FlashpiException;

/**
 * The per-call options (RequestOptions), checked before anything is sent.
 *
 * @phpstan-type CheckedOptions array{
 *     idempotencyKey: string|null,
 *     timeout: float|null,
 *     maxRetries: int|null,
 *     language: string|null,
 *     correlationId: string|null,
 *     extraHeaders: array<string, string>,
 * }
 *
 * @internal
 */
final class Options
{
    public const NAMES = ['idempotencyKey', 'timeout', 'maxRetries', 'language', 'correlationId', 'extraHeaders'];

    private function __construct() {}

    /**
     * The caller's options, with the values that become headers checked and trimmed, so the
     * attempts, the errors and a recovered read all use the same ones. A null option means the
     * client's setting.
     *
     * @param array<mixed> $options
     *
     * @return CheckedOptions
     *
     * @throws \InvalidArgumentException An unknown option, or a header value that cannot be sent.
     * @throws FlashpiException A timeout or maxRetries out of range.
     */
    public static function check(#[\SensitiveParameter] array $options): array
    {
        $unknown = array_diff(array_map('strval', array_keys($options)), self::NAMES);
        if ($unknown !== []) {
            sort($unknown);

            throw new \InvalidArgumentException('Unknown request options: ' . implode(', ', $unknown));
        }
        $text = static fn(string $name): ?string => ($options[$name] ?? null) === null
            ? null
            : Validate::headerValue($name, $options[$name]);
        $timeout = $options['timeout'] ?? null;
        $maxRetries = $options['maxRetries'] ?? null;

        return [
            'idempotencyKey' => $text('idempotencyKey'),
            'timeout' => $timeout === null ? null : Validate::timeout('timeout', $timeout),
            'maxRetries' => $maxRetries === null ? null : Validate::retries('maxRetries', $maxRetries),
            'language' => $text('language'),
            'correlationId' => $text('correlationId'),
            'extraHeaders' => Validate::headers('extraHeaders', $options['extraHeaders'] ?? null),
        ];
    }

    /**
     * Checked options as a caller would pass them again, without the Idempotency-Key: for the
     * read of a recovered document.
     *
     * @param CheckedOptions $options
     *
     * @return array<string, mixed>
     */
    public static function forRead(array $options): array
    {
        $read = $options;
        unset($read['idempotencyKey']);

        return $read;
    }
}
