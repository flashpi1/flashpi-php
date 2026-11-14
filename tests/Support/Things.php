<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use Flashpi\Internal\Multipart;
use Flashpi\Internal\Path;
use Flashpi\Internal\RequestSpec;
use Flashpi\Internal\Resource;
use Flashpi\Page;

/**
 * A resource of the tests, declared as the real resources declare their operations, so the core
 * tests run on the same rules. `issue()` is a document POST (POST /v1/invoices).
 */
final class Things extends Resource
{
    public const XML = 'application/xml, application/problem+json';

    public const PDF = 'application/pdf, application/problem+json';

    /**
     * @param array<mixed> $options
     */
    public function status(array $options = []): mixed
    {
        return $this->send(new RequestSpec('GET', '/v1/status', options: $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function get(string $id, array $options = []): mixed
    {
        return $this->send(new RequestSpec('GET', Path::of('/v1/things/{}', $id), options: $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function getNested(string $first, string $second, array $options = []): mixed
    {
        return $this->send(new RequestSpec('GET', Path::of('/v1/things/{}/parts/{}', $first, $second), options: $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function create(mixed $body, array $options = []): mixed
    {
        return $this->send(new RequestSpec('POST', '/v1/things', body: $body, options: $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function issue(mixed $body, array $options = []): mixed
    {
        return $this->send(RequestSpec::issueDocument('/v1/invoices', $body, $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function update(string $id, mixed $body, array $options = []): mixed
    {
        return $this->send(new RequestSpec('PATCH', Path::of('/v1/things/{}', $id), body: $body, options: $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function delete(string $id, array $options = []): mixed
    {
        return $this->send(new RequestSpec('DELETE', Path::of('/v1/things/{}', $id), responseType: 'none', options: $options));
    }

    /**
     * @param array<string, mixed>|null $query
     * @param array<mixed> $options
     *
     * @return Page<mixed>
     */
    public function list(?array $query = null, array $options = []): Page
    {
        return $this->paginate(new RequestSpec('GET', '/v1/things', query: $query, options: $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function text(string $id, array $options = []): mixed
    {
        return $this->send(new RequestSpec('GET', Path::of('/v1/things/{}/xml', $id), accept: self::XML, responseType: 'text', options: $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function raw(string $id, array $options = []): mixed
    {
        return $this->send(new RequestSpec('GET', Path::of('/v1/things/{}/pdf', $id), accept: self::PDF, responseType: 'bytes', options: $options));
    }

    /**
     * @param array<mixed> $options
     */
    public function upload(
        #[\SensitiveParameter]
        string $content,
        #[\SensitiveParameter]
        string $password,
        array $options = [],
    ): mixed {
        $form = new Multipart(
            ['password' => $password],
            ['file' => ['filename' => 'certificate.p12', 'contents' => $content, 'contentType' => 'application/x-pkcs12']],
        );

        return $this->send(new RequestSpec('POST', '/v1/uploads', multipart: $form, options: $options));
    }

    /**
     * A method that sends nothing.
     */
    public function nothing(): int
    {
        return 1;
    }

    public function withResponse(): ThingsWithResponse
    {
        return $this->view(ThingsWithResponse::class);
    }
}
