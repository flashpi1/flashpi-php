<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use Psr\Http\Message\RequestInterface;

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
     * The redacted arguments (#[\SensitiveParameter]) are not. In every frame, a request whose
     * Authorization header holds the secret counts.
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
                // A request in any frame (Guzzle's, a middleware's) carries the API key in its
                // Authorization header.
                foreach ($frame['args'] ?? [] as $argument) {
                    if ($argument instanceof RequestInterface && str_contains($argument->getHeaderLine('Authorization'), $secret)) {
                        $found[] = "request in frame {$index}: " . $class . ($frame['type'] ?? '') . $frame['function'];
                    }
                }
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
     * Whether var_dump() or print_r() of `$value` (or of `(array) $value`) shows `$secret`, or
     * what var_export() and Symfony's VarDumper show: every property, private ones included, and
     * what a closure captured and is bound to.
     */
    public static function inDumps(mixed $value, string $secret): bool
    {
        ob_start();
        var_dump($value);
        if (\is_object($value)) {
            var_dump((array) $value);
        }
        $dump = (string) ob_get_clean();
        $seen = [];

        return str_contains($dump, $secret)
            || str_contains(print_r($value, true), $secret)
            || (\is_object($value) && str_contains(print_r((array) $value, true), $secret))
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
                // var_dump(), print_r() and VarDumper show what a closure captured and the object
                // it is bound to.
                $function = new \ReflectionFunction($value);

                return self::shows($function->getStaticVariables(), $secret, $seen, $depth + 1)
                    || self::shows($function->getClosureThis(), $secret, $seen, $depth + 1);
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
