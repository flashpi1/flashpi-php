<?php

declare(strict_types=1);

namespace Flashpi\Scripts\OpenApi;

/**
 * The answer to one GET of the contract.
 */
final class Answer
{
    public function __construct(
        public readonly int $status,
        public readonly ?string $location,
        public readonly string $body,
    ) {}

    /**
     * Reads the status and the Location from the header lines of PHP's HTTP stream wrapper
     * (the last status line wins: an interim 100 Continue may come first).
     *
     * @param list<string> $headers
     */
    public static function fromHeaders(array $headers, string $body): self
    {
        $status = 0;
        $location = null;
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
                $location = null;
            } elseif (preg_match('/^location:\s*(.*)$/i', $line, $match) === 1) {
                $location = trim($match[1]);
            }
        }

        return new self($status, $location, $body);
    }
}
