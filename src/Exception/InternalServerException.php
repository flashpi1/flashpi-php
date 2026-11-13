<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * 5xx: Flashpi failed to answer. Requests are retried automatically before this is thrown.
 */
final class InternalServerException extends ApiException {}
