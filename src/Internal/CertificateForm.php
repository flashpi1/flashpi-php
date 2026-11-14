<?php

declare(strict_types=1);

namespace Flashpi\Internal;

use Flashpi\Exception\FlashpiException;
use Psr\Http\Message\StreamInterface;

/**
 * The multipart body of a .p12 upload (SdkTypes' CertificateUpload), for
 * `$flashpi->issuers->certificates->upload()` and `$flashpi->issuerOnboardings->create()`: one
 * `password` field and one `file` part.
 *
 * The file is read once here, so every retry sends the same bytes (a stream could not be read
 * twice). Everything is checked before anything is sent. Every parameter that holds the upload,
 * the file or its password is a `#[\SensitiveParameter]`, so the trace of an error never shows
 * them; PHP's traces carry arguments, not local variables.
 *
 * @internal
 */
final class CertificateForm
{
    /** Largest .p12 the API accepts (413 `payload_too_large` above it). */
    public const MAX_BYTES = 1_048_576;

    public const DEFAULT_FILENAME = 'certificate.p12';

    public const CONTENT_TYPE = 'application/x-pkcs12';

    private function __construct() {}

    /**
     * The multipart body of an upload (a CertificateUpload, checked here: PHP does not enforce
     * PHPDoc types).
     *
     * @param array<mixed> $upload
     *
     * @throws FlashpiException A password that is not a string, an empty file, one over 1 MiB, or
     *                          a stream that cannot be read to its end.
     * @throws \InvalidArgumentException A file that is neither a string nor a readable stream, or
     *                                   a file name that is not a string.
     */
    public static function build(#[\SensitiveParameter] array $upload): Multipart
    {
        $password = $upload['password'] ?? null;
        if (!\is_string($password)) {
            throw new FlashpiException('The certificate password must be a string');
        }
        $content = self::read($upload['file'] ?? null);
        if ($content === '') {
            throw new FlashpiException('The certificate file is empty');
        }
        if (\strlen($content) > self::MAX_BYTES) {
            throw new FlashpiException(\sprintf('The certificate file has %d bytes; the API accepts up to %d', \strlen($content), self::MAX_BYTES));
        }
        $filename = $upload['filename'] ?? self::DEFAULT_FILENAME;
        if (!\is_string($filename)) {
            throw new \InvalidArgumentException('The certificate filename must be a string, got ' . get_debug_type($filename));
        }

        return new Multipart(
            ['password' => $password],
            ['file' => ['filename' => $filename, 'contents' => $content, 'contentType' => self::CONTENT_TYPE]],
        );
    }

    /**
     * The bytes of the file: a string as it is, a stream read from where it stands to its end.
     *
     * A stream is read in pieces until its end or one byte past the largest certificate, so a big
     * or wrong file is refused without loading it whole (a pipe or a socket may return fewer
     * bytes than asked before its end).
     */
    private static function read(#[\SensitiveParameter] mixed $file): string
    {
        if (\is_string($file)) {
            return $file;
        }
        if ($file instanceof StreamInterface) {
            if (!$file->isReadable()) {
                throw new \InvalidArgumentException('The certificate file is a PSR-7 stream that cannot be read');
            }

            return self::readUpToTheLimit(
                static fn(int $length): string => $file->read($length),
                static fn(): bool => $file->eof(),
            );
        }
        if (\is_resource($file) && get_resource_type($file) === 'stream') {
            $mode = stream_get_meta_data($file)['mode'];
            if (!str_contains($mode, 'r') && !str_contains($mode, '+')) {
                throw new \InvalidArgumentException("The certificate file is a stream opened for writing only ('{$mode}'): open it with 'rb'");
            }

            return self::readUpToTheLimit(
                static fn(int $length): string => self::fread($file, $length),
                static fn(): bool => feof($file),
            );
        }

        throw new \InvalidArgumentException(
            "The certificate file must be its contents (a string), a stream opened for reading ('rb') or a PSR-7 stream, got " . get_debug_type($file),
        );
    }

    /**
     * Reads pieces until the end, or until one byte past the largest certificate.
     *
     * @param \Closure(int): string $read
     * @param \Closure(): bool $ended
     */
    private static function readUpToTheLimit(
        #[\SensitiveParameter]
        \Closure $read,
        #[\SensitiveParameter]
        \Closure $ended,
    ): string {
        $content = '';
        while (\strlen($content) <= self::MAX_BYTES) {
            $chunk = $read(self::MAX_BYTES + 1 - \strlen($content));
            if ($chunk === '') {
                if ($ended()) {
                    break;
                }

                throw new FlashpiException(
                    'The certificate file is a non-blocking stream with no data ready: pass its contents or a blocking stream',
                );
            }
            $content .= $chunk;
        }
        if (\strlen($content) > self::MAX_BYTES) {
            throw new FlashpiException('The certificate file has more than ' . self::MAX_BYTES . ' bytes, the most the API accepts');
        }

        return $content;
    }

    /**
     * fread() with its failure as an exception: PHP reports one as a notice and `false`.
     *
     * @param open-resource $file
     */
    private static function fread(#[\SensitiveParameter] mixed $file, int $length): string
    {
        $reason = null;
        set_error_handler(static function (int $level, string $message) use (&$reason): bool {
            $reason = $message;

            return true;
        });
        try {
            $chunk = fread($file, max($length, 1));
        } finally {
            restore_error_handler();
        }
        if ($chunk === false) {
            $timedOut = stream_get_meta_data($file)['timed_out'];

            throw new FlashpiException('The certificate file could not be read: ' . ($reason ?? ($timedOut ? 'its read timed out' : 'the read failed')));
        }

        return $chunk;
    }
}
