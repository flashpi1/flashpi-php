<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * 422: the request is well formed but invalid (`validation_failed`, `total_mismatch`, ...).
 */
final class UnprocessableEntityException extends ApiException {}
