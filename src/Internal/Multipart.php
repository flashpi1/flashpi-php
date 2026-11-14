<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * A multipart/form-data body (a .p12 upload). It holds the bytes, so every attempt sends the same
 * parts.
 *
 * The Content-Disposition of a file part is written here, its file name encoded as HTML forms
 * (and httpx) do: `"` as `%22`, `\` as `\\` and control characters as `%XX`, and sent whole.
 * Guzzle's own would keep only its base name, and psr7 2 would not escape it, letting a quote or
 * a line break into the part's headers.
 *
 * The parts are kept in a \SensitiveParameterValue, which shows nothing of what it holds:
 * var_dump(), print_r(), var_export(), a cast to array, Symfony's VarDumper and a stack trace
 * that has this object as an argument never show a certificate or its password, and serialize()
 * fails. Only the part names are shown.
 *
 * @internal
 */
final class Multipart
{
    /** @var \SensitiveParameterValue<list<array{name: string, contents: string, headers?: array<string, string>}>> */
    private readonly \SensitiveParameterValue $parts;

    /** @var list<string> */
    private readonly array $names;

    /**
     * @param array<string, string> $fields Text fields, sent first.
     * @param array<string, array{filename: string, contents: string, contentType: string}> $files
     */
    public function __construct(
        #[\SensitiveParameter]
        array $fields,
        #[\SensitiveParameter]
        array $files,
    ) {
        $parts = [];
        foreach ($fields as $name => $value) {
            $parts[] = ['name' => $name, 'contents' => $value];
        }
        foreach ($files as $name => $file) {
            $parts[] = [
                'name' => $name,
                'contents' => $file['contents'],
                'headers' => [
                    'Content-Disposition' => \sprintf('form-data; name="%s"; filename="%s"', self::encode($name), self::encode($file['filename'])),
                    'Content-Type' => $file['contentType'],
                ],
            ];
        }
        $this->parts = new \SensitiveParameterValue($parts);
        $this->names = [...array_keys($fields), ...array_keys($files)];
    }

    /**
     * The parts, as Guzzle's MultipartStream takes them.
     *
     * @return list<array{name: string, contents: string, headers?: array<string, string>}>
     */
    public function parts(): array
    {
        return $this->parts->getValue();
    }

    /**
     * A name or file name as HTML forms put it between the quotes of a Content-Disposition: `"`
     * as `%22`, `\` doubled and the control characters as `%XX`, as httpx does (which leaves the
     * escape character and DEL, refused in a header by psr7 3). Other bytes (UTF-8 text) are sent
     * as they are.
     */
    public static function encode(string $value): string
    {
        return (string) preg_replace_callback(
            '/["\\\\\x00-\x1F\x7F]/',
            static fn(array $match): string => match ($match[0]) {
                '"' => '%22',
                '\\' => '\\\\',
                default => \sprintf('%%%02X', \ord($match[0])),
            },
            $value,
        );
    }

    /**
     * @return array{names: list<string>}
     */
    public function __debugInfo(): array
    {
        return ['names' => $this->names];
    }
}
