<?php

declare(strict_types=1);

namespace Flashpi\Exception;

/**
 * A webhook did not come from Flashpi, was altered, or is too old: answer 400 and ignore it.
 */
final class WebhookVerificationException extends FlashpiException
{
    public const MISSING_HEADERS = 'missing_headers';

    public const INVALID_TIMESTAMP = 'invalid_timestamp';

    public const TIMESTAMP_OUT_OF_RANGE = 'timestamp_out_of_range';

    public const NO_MATCHING_SIGNATURE = 'no_matching_signature';

    public const INVALID_SECRET = 'invalid_secret';

    public const INVALID_PAYLOAD = 'invalid_payload';

    /**
     * @param self::MISSING_HEADERS|self::INVALID_TIMESTAMP|self::TIMESTAMP_OUT_OF_RANGE|self::NO_MATCHING_SIGNATURE|self::INVALID_SECRET|self::INVALID_PAYLOAD $reason
     *
     * @internal Thrown by the SDK.
     */
    public function __construct(
        /**
         * Why it was rejected: `missing_headers`, `invalid_timestamp`, `timestamp_out_of_range`,
         * `no_matching_signature`, `invalid_secret` or `invalid_payload`.
         *
         * @var self::MISSING_HEADERS|self::INVALID_TIMESTAMP|self::TIMESTAMP_OUT_OF_RANGE|self::NO_MATCHING_SIGNATURE|self::INVALID_SECRET|self::INVALID_PAYLOAD
         */
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
