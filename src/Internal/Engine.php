<?php

declare(strict_types=1);

namespace Flashpi\Internal;

use Flashpi\ApiResponse;
use Flashpi\Exception\ApiException;
use Flashpi\Exception\ConnectionException;
use Flashpi\Exception\FlashpiException;
use Flashpi\Exception\TimeoutException;
use Flashpi\Version;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Requests to the Flashpi API: retries, timeouts, idempotency and typed errors.
 *
 * Every rule lives in request(), and every resource sends through it, so all the operations
 * behave the same. The rules are the Node and Python SDKs':
 *
 * - Up to `maxRetries` retries (2) after network errors, timeouts, 408, 429 and 5xx (not 501, not
 *   `demo_issuer_unavailable`), waiting 0.5 s, 1 s, 2 s ... up to 8 s minus up to 25 % of jitter,
 *   or the answer's Retry-After when it is 60 s or less (a longer one is thrown at once).
 * - Every POST carries an Idempotency-Key (the caller's, or a UUID), reused by its retries, with
 *   the body serialized once.
 * - A 409 `idempotency_key_in_progress` after an attempt that may have reached the API without
 *   its answer reaching us is this call's own attempt still running: it is waited for (1 s, 2 s,
 *   4 s, then 5 s) while the call's time budget lasts, without using up the retries.
 * - A retried document POST answered 422 `idempotency_key_reused` with a `documentId` returns
 *   GET /v1/documents/{documentId}; a retried DELETE answered 404 counts as done.
 * - One deadline per attempt, from sending it to reading the whole answer.
 *
 * @phpstan-import-type CheckedOptions from Options
 *
 * @internal
 */
final class Engine
{
    public const USER_AGENT = 'flashpi-php/' . Version::VERSION;

    /**
     * Longest Retry-After, in seconds, the SDK waits for by itself; longer waits (daily quotas)
     * are thrown.
     */
    public const MAX_RETRY_AFTER = 60;

    public const IN_PROGRESS = 'idempotency_key_in_progress';

    private const INITIAL_RETRY_DELAY = 0.5;

    private const MAX_RETRY_DELAY = 8.0;

    /** Waits between checks on an earlier attempt that the API is still running. */
    private const IN_PROGRESS_FIRST_WAIT = 1.0;

    private const IN_PROGRESS_MAX_WAIT = 5.0;

    /**
     * Guzzle 8's timeout exceptions, by name: they do not exist in Guzzle 7, where static analysis
     * would report them.
     */
    private const GUZZLE_8_TIMEOUTS = [
        'GuzzleHttp\\Exception\\ConnectTimeoutException',
        'GuzzleHttp\\Exception\\NetworkTimeoutException',
        'GuzzleHttp\\Exception\\ResponseTimeoutException',
    ];

    /** curl's CURLE_OPERATION_TIMEDOUT, in the handler context of Guzzle 7's exceptions. */
    private const CURL_TIMEOUT = 28;

    private const READ_CHUNK = 65536;

    /** Headers of a 404 that a 204 built from it must not carry. */
    private const BODY_HEADERS = ['content-length', 'content-type', 'content-encoding', 'transfer-encoding'];

    /**
     * The members of the API's request bodies that are lists (each needs at least one item). An
     * empty PHP array under any other name is an empty object: tests/OperationsTest.php checks this
     * list against the contract.
     */
    public const LIST_FIELDS = ['enabledEvents', 'items', 'payments', 'reasons', 'recipients', 'supportingDocuments', 'taxes', 'withholdings'];

    private Clock $clock;

    public function __construct(
        private readonly ClientConfig $config,
        private readonly ClientInterface $http,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
    }

    public function config(): ClientConfig
    {
        return $this->config;
    }

    /**
     * Replaces the clock of the retries (tests).
     */
    public function useClock(Clock $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * Sends one call, with its retries and recoveries.
     *
     * @return ApiResponse<mixed>
     *
     * @throws FlashpiException
     * @throws \InvalidArgumentException An option, a header, the query or the body cannot be sent.
     */
    public function request(RequestSpec $spec): ApiResponse
    {
        $call = $this->prepare($spec);
        $started = $this->clock->now();
        $attempt = 0;
        $retries = 0;
        $waits = 0;
        // Whether an earlier attempt may have reached the API without its answer reaching us (a
        // network error, a timeout, or a 5xx from a proxy). Only then can a 409
        // idempotency_key_in_progress be this call's own attempt still running.
        $unanswered = false;

        while (true) {
            try {
                $outcome = $this->attempt($spec, $call);
            } catch (ConnectionException $error) {
                $outcome = $error;
            } catch (FlashpiException $error) {
                $error->idempotencyKey = $call->idempotencyKey;

                throw $error;
            }
            if ($outcome instanceof ApiResponse) {
                return $outcome;
            }
            $failure = $outcome;
            $failure->idempotencyKey = $call->idempotencyKey;

            if ($attempt > 0 && $failure instanceof ApiException) {
                $documentId = self::recoverableDocument($spec, $failure);
                if ($documentId !== null) {
                    return $this->recoverDocument($call, $documentId);
                }
                // A DELETE retried after a lost answer finds the resource already gone.
                if ($spec->method === 'DELETE' && $failure->status === 404) {
                    return self::deleted($failure);
                }
                // This call's earlier attempt is still running on the API (a sync issue whose
                // answer was lost): wait for it with the same key while the call has time left.
                // These checks do not count as retries; once the time is up, the normal retry
                // rules apply.
                if ($unanswered && $failure->status === 409 && $failure->errorCode === self::IN_PROGRESS) {
                    $wait = min(self::IN_PROGRESS_FIRST_WAIT * 2 ** min($waits, 16), self::IN_PROGRESS_MAX_WAIT);
                    if ($this->clock->now() + $wait - $started <= $call->timeout) {
                        $waits++;
                        $attempt++;
                        $this->clock->sleep($wait);

                        continue;
                    }
                }
            }

            if ($failure instanceof ConnectionException || ($failure->status >= 500 && $failure->problem === null)) {
                $unanswered = true;
            }
            $delay = $this->retryDelay($failure, $retries);
            if ($delay === null || $retries >= $call->maxRetries) {
                throw $failure;
            }
            $retries++;
            $attempt++;
            $this->clock->sleep($delay);
        }
    }

    /**
     * Seconds to wait before retrying, or null when the failure must not be retried.
     *
     * POSTs are safe to retry because they keep their Idempotency-Key.
     */
    public function retryDelay(FlashpiException $failure, int $retries): ?float
    {
        if ($failure instanceof ConnectionException) {
            return $this->backoff($retries);
        }
        if (!$failure instanceof ApiException) {
            return null;
        }
        if ($failure->status === 409) {
            return $failure->errorCode === self::IN_PROGRESS
                ? max(self::IN_PROGRESS_FIRST_WAIT, $this->backoff($retries))
                : null;
        }
        $status = $failure->status;
        $transient = $status === 408 || $status === 429 || ($status >= 500 && $status !== 501);
        // The shared demo certificate is missing: no retry within seconds will fix that.
        if (!$transient || $failure->errorCode === 'demo_issuer_unavailable') {
            return null;
        }
        $retryAfter = RetryAfter::parse($failure->header('retry-after'));
        if ($retryAfter === null) {
            return $this->backoff($retries);
        }

        return $retryAfter <= self::MAX_RETRY_AFTER ? (float) $retryAfter : null;
    }

    /**
     * Exponential backoff from 0.5 s up to 8 s, minus up to 25 % of jitter.
     */
    public function backoff(int $retries): float
    {
        $delay = min(self::INITIAL_RETRY_DELAY * 2 ** min($retries, 16), self::MAX_RETRY_DELAY);

        return round($delay * (1 - $this->clock->random() * 0.25), 3);
    }

    /**
     * Checks the options and builds what the attempts share. Throws before anything is sent.
     */
    private function prepare(RequestSpec $spec): Call
    {
        $options = Options::check($spec->options);
        $timeout = max($options['timeout'] ?? $this->config->timeout, $spec->minTimeout);

        return new Call(
            $options,
            $options['maxRetries'] ?? $this->config->maxRetries,
            min($timeout, Validate::MAX_TIMEOUT),
            $this->url($spec->path, $spec->query),
            $spec->method === 'POST' ? self::idempotencyKey($options) : null,
            $spec->body === null || $spec->multipart !== null ? null : self::json($spec->body),
        );
    }

    /**
     * One attempt within its deadline, from sending it to reading the whole answer.
     *
     * Guzzle's `timeout` limits the whole transfer with the curl handler. The stream handler
     * limits the headers only between packets (Guzzle 7 and 8), so headers that trickle in keep
     * the attempt going until they are complete; Guzzle 8's limits the body to the deadline, and
     * Guzzle 7's each read only. The answer is then read here, its body checked against the
     * deadline as each chunk arrives, and the deadline checked once more at the end, so an answer
     * whose end came late fails with any handler, once it has arrived.
     *
     * @return ApiResponse<mixed>|ApiException
     */
    private function attempt(RequestSpec $spec, Call $call): ApiResponse|ApiException
    {
        $headers = $this->headers($spec, $call);
        $body = $call->content;
        if ($spec->multipart !== null) {
            $body = new MultipartStream($spec->multipart->parts());
            $headers['Content-Type'] = 'multipart/form-data; boundary=' . $body->getBoundary();
        }
        try {
            $request = new Request($spec->method, $call->url, $headers, $body);
        } catch (\InvalidArgumentException) {
            // Without PSR-7's reason, which quotes the value it refused: it may be a header's.
            throw new FlashpiException("{$spec->method} {$call->url} was not sent: it is not a valid HTTP request");
        }
        $deadline = $this->clock->now() + $call->timeout;
        try {
            $response = $this->http->send($request, self::transfer($call->timeout));
        } catch (\Throwable $error) {
            // A caller's client may still throw for error statuses: its answer is the API's.
            if (!$error instanceof BadResponseException) {
                throw $this->transferError($error, $spec, $call, $deadline);
            }
            $response = $error->getResponse();
        }
        $content = $this->read($response, $spec, $call, $deadline);
        if ($this->clock->now() > $deadline) {
            throw $this->timeoutError($spec, $call);
        }
        self::checkLength($response, $content, $spec, $call);

        return $this->answer($response, $content, $spec->responseType);
    }

    /**
     * The options of every attempt for Guzzle: no redirects, no exceptions for error statuses,
     * one deadline for the transfer.
     *
     * @return array{
     *     allow_redirects: false,
     *     http_errors: false,
     *     stream: false,
     *     expect: false,
     *     decode_content: true,
     *     timeout: float,
     *     connect_timeout: float,
     *     read_timeout: float,
     * }
     */
    private static function transfer(float $timeout): array
    {
        // Guzzle 8 refuses a timeout under one millisecond.
        $seconds = max($timeout, 0.001);

        return [
            'allow_redirects' => false,
            'http_errors' => false,
            'stream' => false,
            'expect' => false,
            'decode_content' => true,
            'timeout' => $seconds,
            'connect_timeout' => $seconds,
            'read_timeout' => $seconds,
        ];
    }

    /**
     * The whole body of an answer, refused once the attempt's deadline has passed (the body is
     * then closed).
     */
    private function read(ResponseInterface $response, RequestSpec $spec, Call $call, float $deadline): string
    {
        $content = '';
        $body = $response->getBody();
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (!$body->eof()) {
                $chunk = $body->read(self::READ_CHUNK);
                if ($this->clock->now() > $deadline) {
                    $body->close();

                    throw $this->timeoutError($spec, $call);
                }
                if ($chunk === '') {
                    break;
                }
                $content .= $chunk;
            }
        } catch (TimeoutException $error) {
            throw $error;
        } catch (\RuntimeException $error) {
            throw new ConnectionException("{$spec->method} {$call->url} failed: " . self::reason($error));
        }

        return $content;
    }

    /**
     * Refuses an answer whose connection closed before the body its Content-Length declares.
     *
     * curl and Guzzle 8's stream handler report it as a failed transfer; Guzzle 7's stream handler
     * returns what arrived, which would be a truncated PDF or JSON that does not parse. It is a
     * network failure, retried like one. A body the handler decoded (gzip) carries no
     * Content-Length of its own, so it is not compared.
     *
     * @throws ConnectionException
     */
    private static function checkLength(ResponseInterface $response, string $content, RequestSpec $spec, Call $call): void
    {
        $declared = $response->getHeaderLine('Content-Length');
        if (
            !ctype_digit($declared)
            || $response->hasHeader('Content-Encoding')
            || $response->hasHeader('X-Encoded-Content-Length')
            || \in_array($response->getStatusCode(), [204, 304], true)
            || \strlen($content) >= (int) $declared
        ) {
            return;
        }

        throw new ConnectionException("{$spec->method} {$call->url} failed: the answer ended after " . \strlen($content) . " of its {$declared} bytes");
    }

    /**
     * The data of a successful answer, or the ApiException of an error status.
     *
     * @return ApiResponse<mixed>|ApiException
     */
    private function answer(ResponseInterface $response, string $content, string $responseType): ApiResponse|ApiException
    {
        $status = $response->getStatusCode();
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = array_values($values);
        }
        if ($status < 200 || $status >= 300) {
            return ApiException::fromResponse($status, $headers, $content);
        }
        if ($responseType === 'text' || $responseType === 'bytes') {
            $data = $content;
        } elseif ($responseType === 'none' || $status === 204 || $content === '') {
            $data = null;
        } else {
            try {
                $data = json_decode($content, true, 512, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (\JsonException $error) {
                throw new FlashpiException("The API answered {$status} with a body that is not JSON", $error);
            }
        }
        $requestId = $headers['x-request-id'] ?? null;

        return new ApiResponse(
            $data,
            $response->withBody(Utils::streamFor($content)),
            $requestId === null ? null : implode(', ', $requestId),
        );
    }

    /**
     * What a failed transfer means: a timeout or another failure of the transfer (both retried),
     * or anything else (a bug in a middleware, an invalid Guzzle option), not retried.
     *
     * Every failure of a Guzzle transfer counts, whatever its class: Guzzle 7 and 8 classify them
     * differently (Guzzle 8's stream handler reports a timeout while waiting for the headers as a
     * plain RequestException). No error of the HTTP client is kept or thrown as it is: the frames
     * of its trace and Guzzle's exceptions hold the request, whose Authorization header carries
     * the API key. Only its class and message are kept; an \InvalidArgumentException stays one.
     */
    private function transferError(#[\SensitiveParameter] \Throwable $error, RequestSpec $spec, Call $call, float $deadline): \Throwable
    {
        if (
            !$error instanceof TransferException
            && !$error instanceof NetworkExceptionInterface
            && !$error instanceof RequestExceptionInterface
        ) {
            $message = "{$spec->method} {$call->url} was not sent: " . $error::class . ': ' . self::reason($error);

            return $error instanceof \InvalidArgumentException ? new \InvalidArgumentException($message) : new FlashpiException($message);
        }
        if (self::isTimeout($error) || $this->clock->now() >= $deadline) {
            return $this->timeoutError($spec, $call);
        }

        return new ConnectionException("{$spec->method} {$call->url} failed: " . self::reason($error));
    }

    private static function isTimeout(#[\SensitiveParameter] \Throwable $error): bool
    {
        foreach (self::GUZZLE_8_TIMEOUTS as $class) {
            if (is_a($error, $class)) {
                return true;
            }
        }
        if (method_exists($error, 'getHandlerContext')) {
            $context = $error->getHandlerContext();

            return \is_array($context) && ($context['errno'] ?? null) === self::CURL_TIMEOUT;
        }

        return false;
    }

    private function timeoutError(RequestSpec $spec, Call $call): TimeoutException
    {
        $seconds = rtrim(rtrim(\sprintf('%.3f', $call->timeout), '0'), '.');

        return new TimeoutException("{$spec->method} {$call->url} timed out after {$seconds} seconds");
    }

    private static function reason(#[\SensitiveParameter] \Throwable $error): string
    {
        return $error->getMessage() !== '' ? $error->getMessage() : $error::class;
    }

    /**
     * The headers of an attempt, from the lowest priority to the highest: the SDK's defaults, the
     * client's language and headers, the call's language and headers, then the SDK's own.
     *
     * @return array<string, string>
     */
    private function headers(RequestSpec $spec, Call $call): array
    {
        $headers = [];
        $set = static function (string $name, string $value) use (&$headers): void {
            $headers[strtolower($name)] = [$name, $value];
        };
        $set('Accept', $spec->accept);
        $set('User-Agent', self::USER_AGENT);
        if (($this->config->language ?? '') !== '') {
            $set('Accept-Language', (string) $this->config->language);
        }
        foreach ($this->config->defaultHeaders as $name => $value) {
            $set($name, $value);
        }
        $options = $call->options;
        if (($options['language'] ?? '') !== '') {
            $set('Accept-Language', (string) $options['language']);
        }
        foreach ($options['extraHeaders'] as $name => $value) {
            $set($name, $value);
        }
        if ($options['correlationId'] !== null) {
            $set('X-Correlation-Id', $options['correlationId']);
        }
        if ($call->idempotencyKey !== null) {
            $set('Idempotency-Key', $call->idempotencyKey);
        }
        if ($spec->multipart !== null) {
            unset($headers['content-type']);
        } elseif ($spec->body !== null) {
            $set('Content-Type', 'application/json');
        }
        $set('Authorization', $this->config->authorization());

        return array_column($headers, 1, 0);
    }

    /**
     * The caller's Idempotency-Key of a POST (the option, or the last spelling of the header), or
     * a new one.
     *
     * @param CheckedOptions $options
     */
    private static function idempotencyKey(array $options): string
    {
        if ($options['idempotencyKey'] !== null) {
            return $options['idempotencyKey'];
        }
        $header = null;
        foreach ($options['extraHeaders'] as $name => $value) {
            if (strtolower($name) === 'idempotency-key') {
                $header = $value;
            }
        }

        return $header ?? self::uuid4();
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Compact UTF-8 JSON, like JSON.stringify.
     *
     * PHP has one empty array for an empty list and an empty object: it is sent as a list under a
     * name of LIST_FIELDS, and as an object anywhere else (the body itself, `additionalInfo`,
     * `options`, `branding`, ...), as the API expects.
     *
     * @throws \InvalidArgumentException When the body cannot be JSON (NaN, INF, invalid UTF-8).
     */
    private static function json(mixed $body): string
    {
        try {
            return json_encode(self::objects($body, null), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('The request body cannot be sent as JSON: ' . $error->getMessage(), 0, $error);
        }
    }

    /**
     * A value of a body with its empty arrays turned into empty objects, except those of the
     * members LIST_FIELDS names. Objects (JsonSerializable) are left as they are.
     *
     * @param int|string|null $name The member the value is the value of (null for the body).
     */
    private static function objects(mixed $value, int|string|null $name): mixed
    {
        if ($value === []) {
            return \is_string($name) && \in_array($name, self::LIST_FIELDS, true) ? [] : new \stdClass();
        }
        if (!\is_array($value)) {
            return $value;
        }
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = self::objects($item, $key);
        }

        return $result;
    }

    /**
     * The URL of a request. Query values that are null are left out.
     *
     * @param array<string, mixed>|null $query
     *
     * @throws \InvalidArgumentException A query value that is not a string, a number or a boolean.
     */
    private function url(string $path, ?array $query): string
    {
        $pairs = [];
        foreach ($query ?? [] as $name => $value) {
            if ($value === null) {
                continue;
            }
            if (\is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (\is_int($value) || \is_float($value) || $value instanceof \Stringable) {
                $value = (string) $value;
            } elseif (!\is_string($value)) {
                throw new \InvalidArgumentException("The query parameter {$name} must be a string, a number or a boolean, got " . get_debug_type($value));
            }
            $pairs[] = self::encodeQuery((string) $name) . '=' . self::encodeQuery($value);
        }
        $search = implode('&', $pairs);

        return rtrim($this->config->baseUrl, '/') . $path . ($search !== '' ? '?' . $search : '');
    }

    /**
     * Percent-encoding with `+` for spaces, as HTML forms and Python's urlencode() write a query.
     */
    private static function encodeQuery(string $value): string
    {
        return str_replace('%20', '+', rawurlencode($value));
    }

    /**
     * The id of the document an earlier attempt of this call created, if the answer shows one.
     */
    private static function recoverableDocument(RequestSpec $spec, ApiException $failure): ?string
    {
        if (!$spec->recoverDocument || $failure->status !== 422 || $failure->errorCode !== 'idempotency_key_reused') {
            return null;
        }
        $documentId = $failure->problem['documentId'] ?? null;

        return \is_string($documentId) && $documentId !== '' ? $documentId : null;
    }

    /**
     * Reads the document an earlier attempt of the call created.
     *
     * A failure to read it keeps the call's key on the exception, so trying again later with that
     * key returns the document instead of issuing another.
     *
     * @return ApiResponse<mixed>
     */
    private function recoverDocument(Call $call, string $documentId): ApiResponse
    {
        try {
            return $this->request(new RequestSpec(
                'GET',
                Path::of('/v1/documents/{}', $documentId),
                options: Options::forRead($call->options),
            ));
        } catch (FlashpiException $error) {
            $error->idempotencyKey = $call->idempotencyKey;

            throw $error;
        }
    }

    /**
     * The result of a DELETE whose resource is gone: a 204 with the 404's headers.
     *
     * @return ApiResponse<mixed>
     */
    private static function deleted(ApiException $failure): ApiResponse
    {
        $headers = array_diff_key($failure->headers, array_flip(self::BODY_HEADERS));

        return new ApiResponse(null, new Response(204, $headers), $failure->requestId);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['config' => $this->config];
    }
}
