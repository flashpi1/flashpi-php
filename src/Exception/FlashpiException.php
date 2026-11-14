<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * Every exception this package throws extends this class.
 *
 * Except for the misuse of an argument, which throws what PHP throws for one: a \TypeError (from
 * the native types, under strict types) or an \InvalidArgumentException (an unknown option, a
 * header with a line break, a body that cannot be JSON, ...).
 */
class FlashpiException extends \RuntimeException
{
    /**
     * The Idempotency-Key a failed POST used (yours or the one the SDK made). To try the same
     * operation again later without issuing twice, pass it back:
     * `create($body, ['idempotencyKey' => $error->idempotencyKey])`. Set by the SDK; null for
     * other methods.
     */
    public ?string $idempotencyKey = null;

    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
