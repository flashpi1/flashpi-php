<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * 400: the request could not be read (malformed JSON, an invalid Idempotency-Key, ...).
 */
final class BadRequestException extends ApiException {}
