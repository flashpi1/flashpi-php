<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * The settings of a client that its requests need.
 *
 * The API key is kept inside a closure: var_dump(), print_r(), var_export(), a cast to array and
 * serialize() never show it.
 *
 * @internal
 */
final class ClientConfig
{
    /** @var \Closure(): string */
    private readonly \Closure $apiKey;

    /**
     * @param array<string, string> $defaultHeaders Checked by Validate::headers().
     */
    public function __construct(
        #[\SensitiveParameter]
        string $apiKey,
        public readonly string $baseUrl,
        public readonly float $timeout,
        public readonly int $maxRetries,
        public readonly ?string $language,
        public readonly array $defaultHeaders,
    ) {
        $this->apiKey = static fn(): string => $apiKey;
    }

    /**
     * `test` for fp_test_ keys (SRI pruebas), `live` for fp_live_ keys (SRI production).
     *
     * @return 'test'|'live'
     */
    public function environment(): string
    {
        return str_starts_with(($this->apiKey)(), 'fp_test_') ? 'test' : 'live';
    }

    /**
     * The value of the Authorization header.
     */
    public function authorization(): string
    {
        return 'Bearer ' . ($this->apiKey)();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'baseUrl' => $this->baseUrl,
            'timeout' => $this->timeout,
            'maxRetries' => $this->maxRetries,
            'language' => $this->language,
            'defaultHeaders' => array_keys($this->defaultHeaders),
        ];
    }
}
