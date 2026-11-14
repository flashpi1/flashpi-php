<?php

declare(strict_types=1);

/*
 * Keeps the SDK's copy of the Flashpi API contract and the types generated from it in step:
 *
 *     php scripts/openapi.php sync      download the public OpenAPI document, then generate
 *     php scripts/openapi.php generate  regenerate src/Types/ApiTypes.php
 *     php scripts/openapi.php check     fail when src/Types/ApiTypes.php is out of date (CI)
 *
 * The source is https://flashpi.com.ec/docs/openapi.json, over HTTPS only. Override it with
 * FLASHPI_OPENAPI_URL, or point FLASHPI_OPENAPI_FILE at a local file. The script needs nothing but
 * PHP: it does not load Composer's autoloader.
 */

use Flashpi\Scripts\OpenApi\Command;
use Flashpi\Scripts\OpenApi\Downloader;

foreach (['GenerationError', 'Field', 'Definition', 'Generator', 'Answer', 'Downloader', 'Command'] as $class) {
    require_once __DIR__ . "/OpenApi/{$class}.php";
}

$arguments = [];
foreach (\is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [] as $argument) {
    if (\is_string($argument)) {
        $arguments[] = $argument;
    }
}

exit((new Command(\dirname(__DIR__), new Downloader(getenv()), \STDOUT, \STDERR))->run($arguments));
