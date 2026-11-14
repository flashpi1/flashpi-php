<?php

declare(strict_types=1);

/*
 * Checks what Composer would install from a tag: GitHub's archive of it, which `git archive`
 * writes from the same .gitattributes. Fails on anything missing or anything else.
 *
 *     git archive --format=tar --prefix=flashpi-php/ -o build/package.tar HEAD
 *     php scripts/package_check.php build/package.tar
 *
 * The script needs nothing but PHP (and its phar extension): it does not load Composer's
 * autoloader.
 */

use Flashpi\Scripts\Package\Archive;

require_once __DIR__ . '/Package/Archive.php';

$arguments = [];
foreach (\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}
$path = $arguments[1] ?? null;
if ($path === null || \count($arguments) !== 2) {
    fwrite(\STDERR, "Usage: php scripts/package_check.php <archive.tar|archive.zip>\n");
    exit(2);
}
try {
    $files = Archive::read($path);
} catch (\Exception $error) {
    fwrite(\STDERR, "Could not read {$path}: {$error->getMessage()}\n");
    exit(1);
}
$problems = Archive::problems($files, Archive::sources(\dirname(__DIR__)));
foreach ($problems as $problem) {
    fwrite(\STDERR, "{$problem}\n");
}
if ($problems !== []) {
    exit(1);
}
fwrite(\STDOUT, basename($path) . ' holds what Composer should install (' . \count($files) . " files)\n");
