<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\StreamInterface;

/**
 * A PSR-7 stream over some content that records the sizes asked for, and returns at most `piece`
 * bytes per read (a pipe or a socket may return fewer bytes than asked before its end).
 */
final class CountingReader
{
    /** @var list<int> */
    public array $asked = [];

    public int $given = 0;

    public function __construct(
        private readonly string $content,
        private readonly ?int $piece = null,
    ) {}

    public function stream(): StreamInterface
    {
        return FnStream::decorate(Utils::streamFor(''), [
            'read' => fn(int $length): string => $this->read($length),
            'eof' => fn(): bool => $this->given >= \strlen($this->content),
        ]);
    }

    /**
     * A stream whose read() fails, like a network share that went away.
     */
    public static function failing(): StreamInterface
    {
        return FnStream::decorate(Utils::streamFor(''), [
            'read' => static function (int $length): never {
                throw new \RuntimeException('Stale file handle');
            },
        ]);
    }

    private function read(int $length): string
    {
        $this->asked[] = $length;
        $chunk = substr($this->content, $this->given, min($length, $this->piece ?? $length));
        $this->given += \strlen($chunk);

        return $chunk;
    }
}
