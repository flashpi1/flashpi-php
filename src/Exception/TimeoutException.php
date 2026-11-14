<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * An attempt took longer than its timeout, from sending it to reading the whole answer.
 */
final class TimeoutException extends ConnectionException {}
