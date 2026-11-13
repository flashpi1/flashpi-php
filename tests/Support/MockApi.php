<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use Flashpi\Flashpi;
use Flashpi\Internal\Engine;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A client on a mocked API: answers from a queue and records what it received.
 *
 * A reply is a response, an exception (thrown like a network failure) or a function of the request
 * and the options, which may answer or throw.
 *
 * @phpstan-type Reply ResponseInterface|\Throwable|\Closure(RequestInterface, array<array-key, mixed>): ResponseInterface
 */
final class MockApi
{
    public readonly Flashpi $client;

    public readonly Things $things;

    public readonly FakeClock $clock;

    /** @var list<RequestInterface> */
    public array $sent = [];

    /** @var list<array<array-key, mixed>> */
    public array $options = [];

    /** @var list<Reply> */
    private array $queue;

    /**
     * @param list<Reply> $replies
     * @param array<string, mixed> $settings The Flashpi constructor's named arguments.
     * @param (\Closure(HandlerStack<callable(RequestInterface, array<mixed>): PromiseInterface<ResponseInterface, mixed>>): void)|null $configure Adds middleware to the client's stack.
     */
    public function __construct(array $replies = [], array $settings = [], ?FakeClock $clock = null, ?\Closure $configure = null)
    {
        $this->queue = $replies;
        $this->clock = $clock ?? new FakeClock();
        $handler = HandlerStack::create(fn(RequestInterface $request, array $options): PromiseInterface => $this->handle($request, $options));
        if ($configure !== null) {
            $configure($handler);
        }
        $this->client = self::flashpi(['apiKey' => Replies::TEST_KEY, ...$settings, 'httpClient' => new Client(['handler' => $handler])]);
        $engine = self::engine($this->client);
        $engine->useClock($this->clock);
        $this->things = new Things($engine);
    }

    /**
     * A client from named arguments of any type, as a caller passes them: the tests pass wrong
     * ones on purpose.
     *
     * @param array<string, mixed> $settings
     */
    public static function flashpi(array $settings): Flashpi
    {
        return new Flashpi(...$settings); // @phpstan-ignore argument.type
    }

    public static function engine(Flashpi $client): Engine
    {
        $engine = (new \ReflectionProperty(Flashpi::class, 'engine'))->getValue($client);
        \assert($engine instanceof Engine);

        return $engine;
    }

    /**
     * The values of a header on each request sent.
     *
     * @return list<string>
     */
    public function headers(string $name): array
    {
        return array_map(static fn(RequestInterface $request): string => $request->getHeaderLine($name), $this->sent);
    }

    public function request(int $index): RequestInterface
    {
        $request = $this->sent[$index] ?? null;
        \assert($request !== null, "No request {$index}");

        return $request;
    }

    /**
     * @param array<array-key, mixed> $options
     *
     * @return PromiseInterface<ResponseInterface, mixed>
     */
    private function handle(RequestInterface $request, array $options): PromiseInterface
    {
        $this->sent[] = $request;
        $this->options[] = $options;
        $reply = array_shift($this->queue);
        if ($reply === null) {
            throw new \OutOfBoundsException("Unexpected request {$request->getMethod()} {$request->getUri()}");
        }
        try {
            if ($reply instanceof \Closure) {
                $reply = $reply($request, $options);
            }
            if ($reply instanceof \Throwable) {
                throw $reply;
            }
            /** @var PromiseInterface<ResponseInterface, mixed> $promise */
            $promise = Create::promiseFor($reply);
        } catch (\Throwable $error) {
            /** @var PromiseInterface<ResponseInterface, mixed> $promise */
            $promise = Create::rejectionFor($error);
        }

        return $promise;
    }
}
