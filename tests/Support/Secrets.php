<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

/**
 * Where a secret can show: what error trackers and logs record of an exception (its message, its
 * string form, the arguments of every frame of its trace and of the exceptions it chains), and
 * what var_dump(), print_r() and var_export() print of an object.
 */
final class Secrets
{
    private function __construct() {}

    /**
     * The places of an exception that show `$secret`, as `frame:function` or `message`.
     *
     * The arguments of the SDK's frames are searched (the caller's own frames hold what the
     * caller passed), objects as var_export() shows them: every property, private ones included.
     * The redacted arguments (#[\SensitiveParameter]) are not.
     *
     * @return list<string>
     */
    public static function inException(\Throwable $error, string $secret): array
    {
        $found = [];
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if (str_contains($current->getMessage(), $secret)) {
                $found[] = 'message of ' . $current::class;
            }
            if (str_contains($current->getTraceAsString(), $secret)) {
                $found[] = 'trace string of ' . $current::class;
            }
            foreach ($current->getTrace() as $index => $frame) {
                $class = $frame['class'] ?? '';
                if (!str_starts_with($class, 'Flashpi\\') || str_starts_with($class, 'Flashpi\\Tests\\')) {
                    continue;
                }
                $seen = [];
                if (self::shows($frame['args'] ?? [], $secret, $seen)) {
                    $found[] = "frame {$index}: " . ($frame['class'] ?? '') . ($frame['type'] ?? '') . $frame['function'];
                }
            }
        }

        return $found;
    }

    /**
     * Whether var_dump(), print_r() or var_export() of `$value` shows `$secret`.
     */
    public static function inDumps(mixed $value, string $secret): bool
    {
        ob_start();
        var_dump($value);
        $dump = (string) ob_get_clean();
        $seen = [];

        return str_contains($dump, $secret)
            || str_contains(print_r($value, true), $secret)
            || self::shows($value, $secret, $seen);
    }

    /**
     * @param array<int, true> $seen The objects already searched.
     */
    private static function shows(mixed $value, string $secret, array &$seen, int $depth = 0): bool
    {
        if (\is_string($value)) {
            return str_contains($value, $secret);
        }
        if ($depth > 12) {
            return false;
        }
        if (\is_object($value)) {
            if ($value instanceof \SensitiveParameterValue || isset($seen[spl_object_id($value)])) {
                return false;
            }
            $seen[spl_object_id($value)] = true;
            if ($value instanceof \Closure) {
                // var_export() shows a closure as \Closure::__set_state(array()).
                return str_contains(var_export($value, true), $secret);
            }
            $value = (array) $value;
        }
        if (\is_array($value)) {
            foreach ($value as $key => $member) {
                if (self::shows((string) $key, $secret, $seen, $depth + 1) || self::shows($member, $secret, $seen, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }
}
