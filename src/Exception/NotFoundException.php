<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * 404: the resource does not exist in this mode (test or live) for this account.
 */
final class NotFoundException extends ApiException {}
