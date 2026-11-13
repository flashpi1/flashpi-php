<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * 403: the key may not do this (read-only, another issuer, IP allowlist, suspended account).
 */
final class PermissionDeniedException extends ApiException {}
