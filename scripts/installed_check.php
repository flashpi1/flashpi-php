<?php

declare(strict_types=1);

/*
 * Uses the installed package the way an application would. CI runs it in a clean project that
 * required flashpi/flashpi-php from its archive (a path repository) with one Guzzle major:
 *
 *     php scripts/installed_check.php /path/to/the/project
 *
 * It fails when the package is not loaded from the project's vendor directory, when the archive
 * brought more than the library, or when a call through the client does not work.
 */

use Flashpi\Flashpi;
use Flashpi\Version;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

$arguments = [];
foreach (\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}
$project = $arguments[1] ?? null;
if ($project === null || !is_file("{$project}/vendor/autoload.php")) {
    fwrite(\STDERR, "Usage: php scripts/installed_check.php <project with vendor/autoload.php>\n");
    exit(2);
}
$project = (string) realpath($project);
require "{$project}/vendor/autoload.php";

$problems = [];
$package = "{$project}/vendor/flashpi/flashpi-php";
$location = (string) (new ReflectionClass(Flashpi::class))->getFileName();
if (!str_starts_with($location, "{$package}/")) {
    $problems[] = "Flashpi was loaded from {$location}, not from {$package}";
}
foreach (['tests', 'scripts', 'docs', 'openapi', 'phpunit.xml.dist', 'phpstan.neon.dist'] as $extra) {
    if (file_exists("{$package}/{$extra}")) {
        $problems[] = "The archive brought {$extra}";
    }
}
$sent = [];
$stack = HandlerStack::create(new MockHandler([new Response(200, ['Content-Type' => 'application/json'], '{"status":"operational"}')]));
$stack->push(Middleware::mapRequest(static function (RequestInterface $request) use (&$sent): RequestInterface {
    $sent[] = $request;

    return $request;
}));
$client = new Flashpi('fp_test_installed_check', httpClient: new Client(['handler' => $stack]));
if ($client->status->get()['status'] !== 'operational') {
    $problems[] = 'Flashpi did not read the status';
}
$request = $sent[0] ?? null;
if (!$request instanceof RequestInterface || $request->getHeaderLine('User-Agent') !== 'flashpi-php/' . Version::VERSION) {
    $problems[] = 'The request did not carry the User-Agent flashpi-php/' . Version::VERSION;
}
foreach ($problems as $problem) {
    fwrite(\STDERR, "{$problem}\n");
}
if ($problems !== []) {
    exit(1);
}
$guzzle = \defined('GuzzleHttp\ClientInterface::MAJOR_VERSION') ? GuzzleHttp\ClientInterface::MAJOR_VERSION : 8;
fwrite(\STDOUT, 'flashpi/flashpi-php ' . Version::VERSION . " works from {$package} on PHP " . \PHP_VERSION . " with Guzzle {$guzzle}\n");
