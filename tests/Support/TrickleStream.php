<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * An answer body that takes `$seconds` of the fake clock for each chunk, like one that trickles
 * in over the network.
 */
final class TrickleStream implements StreamInterface
{
    public bool $closed = false;

    /**
     * @param list<string> $chunks
     */
    public function __construct(private readonly FakeClock $clock, private array $chunks, private readonly float $seconds) {}

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return 0;
    }

    public function eof(): bool
    {
        return $this->chunks === [];
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Not seekable');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Not seekable');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Not writable');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($this->closed) {
            throw new \RuntimeException('The connection was closed');
        }
        $this->clock->advance($this->seconds);

        return array_shift($this->chunks) ?? '';
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(8192);
        }

        return $contents;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
