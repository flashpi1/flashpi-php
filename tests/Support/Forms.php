<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\RequestInterface;

/**
 * Reads the multipart/form-data body of a request the SDK sent.
 */
final class Forms
{
    private const TYPE = '/^multipart\/form-data; boundary="?([^"]+)"?$/';

    private function __construct() {}

    /**
     * The parts of a multipart request, in order: their Content-Disposition, Content-Type and
     * content.
     *
     * @return list<array{disposition: string, type: string|null, body: string}>
     */
    public static function parts(RequestInterface $request): array
    {
        $type = $request->getHeaderLine('content-type');
        Assert::assertMatchesRegularExpression(self::TYPE, $type);
        $boundary = (string) preg_replace(self::TYPE, '$1', $type);
        $parts = [];
        foreach (\array_slice(explode("--{$boundary}", (string) $request->getBody()), 1, -1) as $raw) {
            [$head, $content] = explode("\r\n\r\n", substr($raw, 2), 2) + ['', ''];
            $headers = [];
            foreach (explode("\r\n", $head) as $line) {
                [$name, $value] = explode(':', $line, 2) + ['', ''];
                $headers[strtolower($name)] = trim($value);
            }
            $parts[] = ['disposition' => $headers['content-disposition'] ?? '', 'type' => $headers['content-type'] ?? null, 'body' => substr($content, 0, -2)];
        }

        return $parts;
    }

    /**
     * The parts of a .p12 upload: the password and the file, as [name, file name, content type,
     * content] (Python: tests/test_resources.py's sent_form()).
     *
     * @return list<array{string|null, string|null, string|null, string}>
     */
    public static function upload(RequestInterface $request): array
    {
        return array_map(
            static function (array $part): array {
                preg_match('/^form-data; name="([^"]*)"/', $part['disposition'], $name);
                preg_match('/; filename="([^"]*)"$/', $part['disposition'], $filename);

                return [$name[1] ?? null, $filename[1] ?? null, $part['type'], $part['body']];
            },
            self::parts($request),
        );
    }
}
