<?php

declare(strict_types=1);

// PER Coding Style 2.0, with strict types in every file and sorted, used imports.

$finder = (new PhpCsFixer\Finder())
    ->in(array_filter([__DIR__ . '/src', __DIR__ . '/tests', __DIR__ . '/scripts'], 'is_dir'))
    ->append([__FILE__]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PER-CS2x0' => true,
        '@PER-CS2x0:risky' => true,
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => [
            'imports_order' => ['class', 'function', 'const'],
            'sort_algorithm' => 'alpha',
        ],
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
