# Contributing to flashpi/flashpi-php

Read [docs/decisions.md](docs/decisions.md) first: it records why the SDK is built the way it
is. Decisions about the product (names, behavior, releases) belong to the Flashpi team: ask
before changing them.

## Setup

PHP 8.2 or later with the curl, mbstring and phar extensions, and
[Composer](https://getcomposer.org/):

```bash
composer install
```

`composer.lock` is not committed: a library is tested against the newest and the oldest versions
`composer.json` allows. Without PHP on your machine, run every command in the official images,
for example `docker run --rm -v "$PWD":/app -w /app composer:2 composer install`, then
`docker run --rm -v "$PWD":/app -w /app php:8.5-cli vendor/bin/phpunit`.

## Checks

Run the same checks as CI before every commit:

```bash
composer validate --strict && composer cs && composer analyse && composer test
```

- `composer cs` checks the style (PHP-CS-Fixer, PER-CS 2.0; `composer cs:fix` fixes it).
- `composer analyse` runs PHPStan at level max, with bleeding edge, over `src`, `tests` and
  `scripts`. Its configuration (`phpstan.neon.dist`) adds flags of its own; the consumer files of
  `tests/Types/Fixtures` and the README examples are also analysed with a consumer's
  configuration (`tests/Types/Fixtures/phpstan.neon`) by `tests/Types/ConsumerTypesTest.php`,
  and the `php` blocks of the public docblocks by `tests/Types/DocblockExamplesTest.php`.
- `composer test` runs PHPUnit, strict about warnings, deprecations and output. It also checks
  that the generated types match `openapi/openapi.json` and that `tests/Examples/Readme/` matches
  the READMEs (`composer openapi:check` and `composer readme:check` do the same on their own).
  Some tests open local sockets (a `php -S` server and a raw one).
- Coverage: install pcov and run `php -d pcov.directory=. vendor/bin/phpunit --coverage-text`.
  pcov only measures `src/` unless told otherwise; `pcov.directory=.` counts the scripts too. CI
  fails below 95 % of lines.
- Static analysis needs Guzzle 8, which is what `composer install` resolves on PHP 8.2 and
  later. Guzzle 7 lacks Guzzle 8's generics, which the test helpers use, and its psr7 installs a
  `getallheaders()` polyfill without a return type: with Guzzle 7 installed, analyse the library
  alone (`vendor/bin/phpstan analyse src scripts`), and `ConsumerTypesTest` skips itself.
- CI also runs the tests on PHP 8.2 to 8.5 with Guzzle 7 and 8, and on PHP 8.2 with the oldest
  versions `composer.json` allows. Composer 2.9 and later refuse versions with security
  advisories by default, among them the oldest Guzzle 7: do it in a scratch copy, with
  `composer config audit.block-insecure false && composer update --prefer-lowest --prefer-stable`.
- `composer package:check` checks what Composer would install from a tag: the archive of `HEAD`
  (so commit first), which `.gitattributes` keeps to the library, `composer.json`, the license
  and the READMEs. CI then installs that archive in a clean project, with Guzzle 7 and with
  Guzzle 8, and runs `scripts/installed_check.php` there.
- `composer test:live` calls the real API in test mode. It needs `FLASHPI_TEST_API_KEY` (an
  `fp_test_` key, read_write) and `FLASHPI_DEMO_ISSUER_ID` (in CI, an organization secret and
  variable shared with flashpi-node and flashpi-python), and issues one test invoice on the demo
  issuer, which has a daily quota: run it only when needed.

## Code conventions

- English for code, identifiers, comments and commit messages. The READMEs are in Spanish
  (`README.md`) and English (`README.en.md`); keep both in step. Every `php` block in them is
  checked: after changing one, run `composer readme:generate`. A block is what would follow
  `<?php` in a file of its own (its `use` imports first, no namespace); variables it reads before
  setting them come from `tests/Examples/Readme/Context.php`, and the reader's functions and the
  bits of Laravel and Symfony it uses are stubbed in `tests/Examples/stubs/`.
- PHP 8.2 or later, `declare(strict_types=1)` in every file, final classes, PHPStan at level max.
- The SDK has **one runtime dependency**, Guzzle (`^7.8.2 || ^8.0`). Models are arrays with the
  API's camelCase keys, typed by the PHPStan array shapes generated from the OpenAPI document; no
  model classes.
- Every request goes through `Flashpi\Internal\Engine::request()`: retries, idempotency,
  recovery and errors run there once, and each operation is declared once, as a `RequestSpec`,
  in a resource of `src/Resources/`.
- Resource methods mirror the API in camelCase: `create`, `list`, `get`, `update`, `delete`,
  then actions named after the operation (`sendEmail`, `rotateSecret`, `replay`, ...). Path ids
  come first, then the body, the filters or the upload, then `array $options = []`. Each resource
  has a `withResponse()` view whose `@method` tags declare the same methods.
  `tests/OperationsTest.php` checks that every operation of the OpenAPI document has exactly one
  method, with the contract's ids, filters, body and answer.

## The API contract

`openapi/openapi.json` is a copy of the public document at
https://flashpi.com.ec/docs/openapi.json and `src/Types/ApiTypes.php` is generated from it. Never
edit either by hand:

```bash
composer openapi:sync      # download the document and regenerate the types
composer openapi:generate  # regenerate from the committed copy
```

The `OpenAPI sync` workflow does this every day and opens a pull request when the contract
changes. New operations or fields may need a method or a hand-written type in
`src/Types/SdkTypes.php`.

## Commits and releases

Commit with [Conventional Commits](https://www.conventionalcommits.org/) (`feat:`, `fix:`,
`docs:`, `test:`, `build:`, `ci:`, `chore:`). They drive the version and the changelog:
[release-please](https://github.com/googleapis/release-please) keeps a release pull request open,
and merging it tags the version (`v0.1.0` first) and creates the GitHub release from the
`Release` workflow. release-please writes the version to `src/Version.php` (the User-Agent's) and
`.release-please-manifest.json`; `composer.json` carries none.

While the version is 0.x, `feat:` raises the minor version and `fix:` the patch version.

Packagist reads the tags from GitHub: there is no upload or publish job, and no Packagist token
in this repository. The Flashpi owner submits the repository to Packagist once
(https://packagist.org/packages/submit, as `flashpi/flashpi-php`) and keeps Packagist's GitHub
integration on, so every new tag appears there within minutes. Composer installs a version from
GitHub's archive of its tag, which `.gitattributes` keeps to the library.

The release PR and the OpenAPI sync PR are opened with a token of the organization's GitHub App,
so CI runs on them like on any pull request. (One opened with `GITHUB_TOKEN` would get its CI
only after someone approves it.) The Release workflow also starts the live API test (`live.yml`)
on the release branch each time release-please updates it. A dispatched `ci.yml` run does not
count for a pull request's required checks: to run CI again, re-run the PR's own checks.

### The GitHub App

An app of the `flashpi1` organization, installed on `flashpi-php` (as on `flashpi-node` and
`flashpi-python`), with no webhook and these repository permissions: Contents, Pull requests and
Issues, read and write. Its Client ID is the organization variable `FLASHPI_BOT_CLIENT_ID` and
its private key the organization secret `FLASHPI_BOT_PRIVATE_KEY`, shared with this repository.
The workflows create a one-hour token per run with only the permissions each job needs. To
rotate the key, generate a new one in the app's settings, update the organization secret (not a
repository one, which would shadow it here only), then delete the old key.
