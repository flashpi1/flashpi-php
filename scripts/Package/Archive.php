<?php

declare(strict_types=1);

namespace Flashpi\Scripts\Package;

/**
 * The checks of what Composer installs: the archive GitHub makes of a tag, which `git archive`
 * writes from the same .gitattributes (Python: scripts/package_check.py).
 *
 * - It holds the library (every PHP file of src/), composer.json, the license and the READMEs,
 *   and maybe the CHANGELOG, nothing else: no tests, scripts, docs or tool configuration.
 * - Its composer.json names the package, carries no version (Packagist takes it from the tag) and
 *   requires PHP, ext-json and Guzzle only.
 * - The README Packagist shows has no relative link: packagist.org cannot follow one.
 */
final class Archive
{
    public const PACKAGE = 'flashpi/flashpi-php';

    public const REQUIRED = ['LICENSE', 'README.en.md', 'README.md', 'composer.json'];

    public const OPTIONAL = ['CHANGELOG.md'];

    public const REQUIRE = ['php' => '^8.2', 'ext-json' => '*', 'guzzlehttp/guzzle' => '^7.8.2 || ^8.0'];

    private const FENCED_CODE = '/^```.*?^```/ms';

    private const INLINE_LINK = '/\]\(\s*<?([^)\s>]+)/';

    private const LINK_DEFINITION = '/^ {0,3}\[[^\]]+\]:\s*<?([^\s>]+)/m';

    private const ABSOLUTE = ['https://', 'http://', 'mailto:', '#'];

    private function __construct() {}

    /**
     * What is wrong with an archive.
     *
     * @param array<string, string> $files The files of the archive (relative to its top
     *                                     directory) and their contents.
     * @param list<string> $sources The PHP files of src/ in the repository.
     *
     * @return list<string>
     */
    public static function problems(array $files, array $sources): array
    {
        $names = array_keys($files);
        $required = [...self::REQUIRED, ...$sources];
        $problems = [];
        foreach (array_diff($required, $names) as $name) {
            $problems[] = "Missing from the archive: {$name}";
        }
        foreach (array_diff($names, $required, self::OPTIONAL) as $name) {
            $problems[] = "Unexpected in the archive: {$name}";
        }
        if (isset($files['composer.json'])) {
            $problems = [...$problems, ...self::composer($files['composer.json'])];
        }
        foreach (self::relativeLinks($files['README.md'] ?? '') as $link) {
            $problems[] = "README.md links to {$link}: use an absolute URL, since packagist.org cannot follow a relative link";
        }

        return $problems;
    }

    /**
     * The link and image targets of a Markdown text that are not absolute URLs or anchors.
     *
     * @return list<string>
     */
    public static function relativeLinks(string $markdown): array
    {
        $text = (string) preg_replace(self::FENCED_CODE, '', $markdown);
        preg_match_all(self::INLINE_LINK, $text, $inline);
        preg_match_all(self::LINK_DEFINITION, $text, $definitions);
        $links = [];
        foreach ([...$inline[1], ...$definitions[1]] as $target) {
            $absolute = array_filter(self::ABSOLUTE, static fn(string $prefix): bool => str_starts_with($target, $prefix));
            if ($absolute === []) {
                $links[] = $target;
            }
        }

        return $links;
    }

    /**
     * The files of a tar or zip archive with their contents, relative to its top directory when
     * every file sits in one (`git archive --prefix=...`).
     *
     * @return array<string, string>
     */
    public static function read(string $path): array
    {
        $files = [];
        $archive = new \PharData($path);
        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            \assert($file instanceof \PharFileInfo);
            $name = substr($file->getPathname(), \strlen('phar://' . $archive->getPath() . '/'));
            if ($name !== 'pax_global_header') {
                $files[$name] = $file->getContent();
            }
        }
        $tops = array_unique(array_map(static fn(string $name): string => strtok($name, '/') ?: $name, array_keys($files)));
        $top = reset($tops);
        if (\count($tops) === 1 && $top !== false && !isset($files[$top])) {
            $prefix = "{$top}/";
            $files = array_combine(
                array_map(static fn(string $name): string => substr($name, \strlen($prefix)), array_keys($files)),
                $files,
            );
        }
        ksort($files);

        return $files;
    }

    /**
     * The PHP files of src/ in a repository, relative to it.
     *
     * @return list<string>
     */
    public static function sources(string $root): array
    {
        $sources = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$root}/src", \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources[] = 'src/' . substr($file->getPathname(), \strlen("{$root}/src/"));
            }
        }
        sort($sources);

        return $sources;
    }

    /**
     * @return list<string>
     */
    private static function composer(string $json): array
    {
        $composer = json_decode($json, true);
        if (!\is_array($composer)) {
            return ['composer.json is not a JSON object'];
        }
        $problems = [];
        if (($composer['name'] ?? null) !== self::PACKAGE) {
            $problems[] = 'composer.json must name ' . self::PACKAGE;
        }
        if (\array_key_exists('version', $composer)) {
            $problems[] = 'composer.json must carry no version: Packagist takes it from the tag';
        }
        if (($composer['require'] ?? null) !== self::REQUIRE) {
            $problems[] = 'composer.json must require ' . json_encode(self::REQUIRE, \JSON_UNESCAPED_SLASHES) . ' only';
        }
        if (($composer['autoload'] ?? null) !== ['psr-4' => ['Flashpi\\' => 'src/']]) {
            $problems[] = 'composer.json must autoload Flashpi\\ from src/ only';
        }

        return $problems;
    }
}
