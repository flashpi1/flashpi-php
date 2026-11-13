<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * 429: a limit was reached; see retryAfter() and the `limit` and `resetsAt` members of the problem.
 */
final class RateLimitException extends ApiException {}
