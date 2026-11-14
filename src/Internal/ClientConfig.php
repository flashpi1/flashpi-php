<?php

declare(strict_types=1);

namespace Flashpi\Internal;

/**
 * The settings of a client that its requests need.
 *
 * The API key is kept in a \SensitiveParameterValue, which shows nothing of what it holds:
 * var_dump(), print_r(), var_export(), a cast to array, Symfony's VarDumper (dump(), dd()) and a
 * stack trace never show the key, and serialize() fails.
 *
 * @internal
 */
final class ClientConfig
{
    /** @var \SensitiveParameterValue<string> */
    private readonly \SensitiveParameterValue $apiKey;

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
        $this->apiKey = new \SensitiveParameterValue($apiKey);
    }

    /**
     * `test` for fp_test_ keys (SRI pruebas), `live` for fp_live_ keys (SRI production).
     *
     * @return 'test'|'live'
     */
    public function environment(): string
    {
        return str_starts_with($this->key(), 'fp_test_') ? 'test' : 'live';
    }

    /**
     * The value of the Authorization header.
     */
    public function authorization(): string
    {
        return 'Bearer ' . $this->key();
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

    private function key(): string
    {
        return $this->apiKey->getValue();
    }
}
