<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * 401: the API key is missing, malformed, unknown or revoked.
 */
final class AuthenticationException extends ApiException {}
