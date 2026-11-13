<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * The request never got a whole answer (DNS, TLS, a connection reset, ...). Requests are retried
 * automatically before this is thrown.
 *
 * The HTTP client's own exception is not kept as the previous one: it holds the request, whose
 * headers carry the API key. Its message is part of this one.
 */
class ConnectionException extends FlashpiException {}
