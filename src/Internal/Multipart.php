<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * A multipart/form-data body (a .p12 upload). It holds the bytes, so every attempt sends the same
 * parts.
 *
 * The parts are kept inside a closure: var_dump(), print_r(), var_export() and serialize() never
 * show a certificate or its password, and neither does a stack trace that has this object as an
 * argument. Only the part names are shown.
 *
 * @internal
 */
final class Multipart
{
    /** @var \Closure(): list<array{name: string, contents: string, filename?: string, headers?: array<string, string>}> */
    private readonly \Closure $parts;

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
                'filename' => $file['filename'],
                'headers' => ['Content-Type' => $file['contentType']],
            ];
        }
        $this->parts = static fn(): array => $parts;
        $this->names = [...array_keys($fields), ...array_keys($files)];
    }

    /**
     * The parts, as Guzzle's MultipartStream takes them.
     *
     * @return list<array{name: string, contents: string, filename?: string, headers?: array<string, string>}>
     */
    public function parts(): array
    {
        return ($this->parts)();
    }

    /**
     * @return array{names: list<string>}
     */
    public function __debugInfo(): array
    {
        return ['names' => $this->names];
    }
}
