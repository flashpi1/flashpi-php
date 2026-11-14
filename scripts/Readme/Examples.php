<?php

declare(strict_types=1);

namespace Flashpi\Scripts\Readme;

/**
 * The README examples: every ```php block of README.md and README.en.md becomes a file of
 * tests/Examples/Readme/, which PHPStan analyses with a consumer's configuration
 * (tests/Types/Fixtures/phpstan.neon, run by tests/Types/ConsumerTypesTest.php) and PHP-CS-Fixer
 * checks with the rest of the repository. Those files are never run.
 *
 * A block is what would follow `<?php` in a file of its own: its `use` imports first, and no
 * opening tag, `declare` or namespace (each file gets a namespace of its own). A variable that the
 * block reads before setting, such as the `$flashpi` client of "Getting started", is set from the
 * method of the same name of tests/Examples/Readme/Context.php. The reader's own functions
 * (saveForRetry(), handleEvent()) and the bits of Laravel and Symfony the examples use are
 * declared in tests/Examples/stubs/ for PHPStan. Anything else a block leaves undefined is an
 * error PHPStan reports.
 */
final class Examples
{
    public const DIRECTORY = 'tests/Examples/Readme';

    public const CONTEXT = 'tests/Examples/Readme/Context.php';

    public const CONTEXT_CLASS = 'Flashpi\\Tests\\Examples\\Readme\\Context';

    /** Each README and the prefix of its files. */
    public const READMES = ['es' => 'README.md', 'en' => 'README.en.md'];

    private const FENCE = '/^```\s*([\w+-]*)/';

    private const HEADING = '/^#{1,6}\s+(.+?)\s*$/';

    private function __construct() {}

    /**
     * The ```php blocks of a Markdown text, in order, each with the heading above it.
     *
     * @return list<Block>
     *
     * @throws ExampleError A block that is never closed.
     */
    public static function blocks(string $readme, string $text): array
    {
        $found = [];
        $section = '';
        $open = null;
        foreach (explode("\n", str_replace("\r\n", "\n", $text)) as $index => $line) {
            if ($open === null) {
                if (preg_match(self::FENCE, $line, $fence) === 1) {
                    $open = ['line' => $index + 1, 'php' => strtolower($fence[1]) === 'php', 'code' => []];
                } elseif (preg_match(self::HEADING, $line, $heading) === 1) {
                    $section = $heading[1];
                }

                continue;
            }
            if (trim($line) !== '```') {
                $open['code'][] = $line;

                continue;
            }
            if ($open['php']) {
                $found[] = new Block($readme, $open['line'], $section, implode("\n", $open['code']) . "\n");
            }
            $open = null;
        }
        if ($open !== null) {
            throw new ExampleError("{$readme}:{$open['line']}: the code block is never closed");
        }

        return $found;
    }

    /**
     * The names of the public static methods of the context class, each a variable examples may
     * read.
     *
     * @return list<string>
     */
    public static function contextNames(string $source): array
    {
        preg_match_all('/^\s*public static function (\w+)\(/m', $source, $names);

        return $names[1];
    }

    /**
     * The variables of a block, in order of their first appearance, whose first appearance is not
     * an assignment (`$name =`).
     *
     * @return list<string>
     *
     * @throws ExampleError A block that is not valid PHP.
     */
    public static function readBeforeSet(Block $block): array
    {
        $tokens = self::tokens($block);
        $seen = [];
        $read = [];
        foreach ($tokens as $index => $token) {
            if (!\is_array($token) || $token[0] !== \T_VARIABLE) {
                continue;
            }
            $name = substr($token[1], 1);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            if (self::next($tokens, $index) !== '=') {
                $read[] = $name;
            }
        }

        return $read;
    }

    /**
     * The checked file of a block: a header, the namespace of the file, the block's imports, the
     * variables it takes from the context, then the rest of the block.
     *
     * @param list<string> $context The names the context class provides.
     *
     * @throws ExampleError A block that cannot become a checked file.
     */
    public static function file(Block $block, string $prefix, int $number, array $context): string
    {
        if (trim($block->code) === '') {
            throw new ExampleError("{$block->where()}: the example is empty");
        }
        $tokens = preg_match('/^\s*<\?php\b/i', $block->code) === 1 ? [[\T_OPEN_TAG, '<?php', 1]] : self::tokens($block);
        foreach ($tokens as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_OPEN_TAG, \T_NAMESPACE, \T_DECLARE], true)) {
                throw new ExampleError("{$block->where()}: the example cannot have an opening tag, a namespace or a declare: its file adds them");
            }
        }
        $lines = explode("\n", rtrim($block->code, "\n"));
        $imports = [];
        while ($lines !== [] && (str_starts_with($lines[0], 'use ') || ($imports !== [] && $lines[0] === ''))) {
            $imports[] = array_shift($lines);
        }
        while ($imports !== [] && end($imports) === '') {
            array_pop($imports);
        }
        $variables = array_values(array_intersect(self::readBeforeSet($block), $context));
        $assignments = array_map(
            static fn(string $name): string => "\${$name} = \\" . self::CONTEXT_CLASS . "::{$name}();",
            $variables,
        );
        $section = str_replace('*/', '*\\/', $block->section);
        $parts = [
            implode("\n", [
                '<?php',
                '',
                '/*',
                " * Example {$number} of {$block->readme}, under \"{$section}\".",
                ' *',
                ' * GENERATED by scripts/readme.php: edit the README, then run `composer readme:generate`.',
                ' * Analysed by PHPStan with a consumer\'s configuration (tests/Types/Fixtures/phpstan.neon),',
                ' * never run.',
                ' */',
                '',
                'declare(strict_types=1);',
                '',
                'namespace Flashpi\\Tests\\Examples\\Readme\\' . ucfirst($prefix) . \sprintf('%02d', $number) . ';',
            ]),
        ];
        if ($imports !== []) {
            $parts[] = implode("\n", $imports);
        }
        if ($assignments !== []) {
            $parts[] = implode("\n", $assignments);
        }
        $parts[] = implode("\n", $lines);

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * Every file tests/Examples/Readme/ should hold, by file name.
     *
     * @return array<string, string>
     *
     * @throws ExampleError
     */
    public static function expected(string $root): array
    {
        $context = self::contextNames(self::read($root, self::CONTEXT));
        $files = [];
        foreach (self::READMES as $prefix => $readme) {
            foreach (self::blocks($readme, self::read($root, $readme)) as $index => $block) {
                $files[\sprintf('%s_%02d.php', $prefix, $index + 1)] = self::file($block, $prefix, $index + 1, $context);
            }
        }

        return $files;
    }

    /**
     * The example files tests/Examples/Readme/ holds now, by file name.
     *
     * @return array<string, string> Their paths.
     */
    public static function current(string $root): array
    {
        $files = [];
        foreach (glob("{$root}/" . self::DIRECTORY . '/*.php') ?: [] as $path) {
            if (preg_match('/^(' . implode('|', array_keys(self::READMES)) . ')_\d+\.php$/', basename($path)) === 1) {
                $files[basename($path)] = $path;
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return list<array{int, string, int}|string>
     */
    private static function tokens(Block $block): array
    {
        try {
            $tokens = token_get_all("<?php\n" . $block->code, \TOKEN_PARSE);
        } catch (\ParseError $error) {
            throw new ExampleError("{$block->where()}: the example is not valid PHP: {$error->getMessage()}");
        }

        return array_slice($tokens, 1);
    }

    /**
     * The next token that is not white space or a comment, as text.
     *
     * @param list<array{int, string, int}|string> $tokens
     */
    private static function next(array $tokens, int $index): ?string
    {
        for ($next = $index + 1; isset($tokens[$next]); $next++) {
            $token = $tokens[$next];
            if (!\is_array($token)) {
                return $token;
            }
            if (!\in_array($token[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                return $token[1];
            }
        }

        return null;
    }

    private static function read(string $root, string $file): string
    {
        $text = @file_get_contents("{$root}/{$file}");
        if ($text === false) {
            throw new ExampleError("Could not read {$file}");
        }

        return $text;
    }
}
