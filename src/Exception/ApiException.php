<?php

declare(strict_types=1);

namespace Flashpi\Exception;

use Flashpi\Internal\RetryAfter;
use Flashpi\Internal\Text;
use Flashpi\Types\ApiTypes;
use Flashpi\Types\SdkTypes;

/**
 * The API answered with an error status. getCode() is that status.
 *
 * Branch on `errorCode` (stable English slugs such as `validation_failed`), never on the message,
 * which follows the requested language.
 *
 * @phpstan-import-type FieldError from ApiTypes
 * @phpstan-import-type ApiErrorCode from SdkTypes
 * @phpstan-import-type Problem from SdkTypes
 */
class ApiException extends FlashpiException
{
    private const CLASSES = [
        400 => BadRequestException::class,
        401 => AuthenticationException::class,
        403 => PermissionDeniedException::class,
        404 => NotFoundException::class,
        409 => ConflictException::class,
        422 => UnprocessableEntityException::class,
        429 => RateLimitException::class,
    ];

    /** HTTP status of the answer. */
    public readonly int $status;

    /**
     * The error code (`code` of the Problem Details, the `code` of the other SDKs), or null when
     * the answer had no Problem Details (for example a 502 from a proxy).
     *
     * @var ApiErrorCode|null
     */
    public readonly ?string $errorCode;

    /** Short summary of the problem type. */
    public readonly ?string $title;

    /** What went wrong in this request. */
    public readonly ?string $detail;

    /**
     * Field-level problems of a request body or query (may be empty).
     *
     * @var list<FieldError>
     */
    public readonly array $errors;

    /** Flashpi's id of the request (`requestId`, or the `x-request-id` header): share it with support. */
    public readonly ?string $requestId;

    /**
     * The whole Problem Details body, with its extension members (`documentId`, `limit`,
     * `resetsAt`, `expectedTotal`, ...), when there was one.
     *
     * @var Problem|null
     */
    public readonly ?array $problem;

    /**
     * The response headers, by lowercase name.
     *
     * @var array<string, list<string>>
     */
    public readonly array $headers;

    /**
     * @param array<string, list<string>> $headers By lowercase name.
     * @param array<string, mixed>|null $problem A Problem Details body (with a string `code`).
     *
     * @internal Thrown by the SDK: see fromResponse().
     */
    final public function __construct(int $status, array $headers, ?array $problem, string $bodyText = '')
    {
        parent::__construct(self::describe($status, $problem, $bodyText));
        $this->code = $status;
        $this->status = $status;
        $this->headers = $headers;
        /** @var Problem|null $problem */
        $this->problem = $problem;
        $code = $problem['code'] ?? null;
        $this->errorCode = \is_string($code) ? $code : null;
        $title = $problem['title'] ?? null;
        $this->title = \is_string($title) ? $title : null;
        $detail = $problem['detail'] ?? null;
        $this->detail = \is_string($detail) ? $detail : null;
        $errors = $problem['errors'] ?? null;
        /** @var list<FieldError> $fieldErrors */
        $fieldErrors = \is_array($errors) && array_is_list($errors) ? $errors : [];
        $this->errors = $fieldErrors;
        $requestId = $problem['requestId'] ?? null;
        $this->requestId = \is_string($requestId) ? $requestId : ($headers['x-request-id'][0] ?? null);
    }

    /**
     * The exception class that matches the status of an answer.
     *
     * @param array<string, list<string>> $headers By lowercase name.
     *
     * @internal
     */
    public static function fromResponse(int $status, array $headers, string $body): self
    {
        $class = self::CLASSES[$status] ?? ($status >= 500 ? InternalServerException::class : self::class);

        return new $class($status, $headers, self::parseProblem($body), $body);
    }

    /**
     * Seconds the API asked to wait before trying again (`Retry-After`), or null.
     *
     * Daily quota errors point at the next midnight in Ecuador, so this can be hours.
     *
     * @param float|null $now The current Unix time in seconds (default: the clock).
     */
    public function retryAfter(?float $now = null): ?int
    {
        return RetryAfter::parse($this->header('retry-after'), $now);
    }

    /**
     * The first value of a response header (any letter case), or null.
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function parseProblem(string $body): ?array
    {
        if ($body === '') {
            return null;
        }
        try {
            $parsed = json_decode($body, true, 512, \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            // Not JSON: an answer from the edge (CDN or firewall), not from the API.
            return null;
        }
        if (!\is_array($parsed) || !\is_string($parsed['code'] ?? null)) {
            return null;
        }
        $problem = [];
        foreach ($parsed as $name => $value) {
            $problem[(string) $name] = $value;
        }

        return $problem;
    }

    /**
     * @param array<string, mixed>|null $problem
     */
    private static function describe(int $status, ?array $problem, string $body): string
    {
        if ($problem !== null) {
            $detail = $problem['detail'] ?? null;
            if (!\is_string($detail)) {
                $detail = $problem['title'] ?? null;
            }
            $code = $problem['code'] ?? null;
            $code = \is_string($code) ? $code : '';

            return \is_string($detail) ? "{$status} {$code}: {$detail}" : "{$status} {$code}";
        }
        $excerpt = Text::excerpt($body, 200);

        return $excerpt !== '' ? "{$status} {$excerpt}" : "{$status} (no response body)";
    }
}
