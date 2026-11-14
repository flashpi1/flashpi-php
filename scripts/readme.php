<?php

declare(strict_types=1);

/*
 * Keeps the README examples checked: every ```php block of README.md and README.en.md becomes a
 * file of tests/Examples/Readme/, which PHPStan analyses as a consumer's code.
 *
 *     php scripts/readme.php generate  rewrite tests/Examples/Readme/ from the READMEs
 *     php scripts/readme.php check     fail when it is out of date (CI, and the tests)
 *
 * The script needs nothing but PHP: it does not load Composer's autoloader.
 */

use Flashpi\Scripts\Readme\Command;

foreach (['Block', 'ExampleError', 'Examples', 'Command'] as $class) {
    require_once __DIR__ . "/Readme/{$class}.php";
}

$arguments = [];
foreach (\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

exit((new Command(\dirname(__DIR__), \STDOUT, \STDERR))->run($arguments));
