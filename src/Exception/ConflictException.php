<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * 409: the request conflicts with the current state (sequential used, RIDE not ready, ...).
 */
final class ConflictException extends ApiException {}
