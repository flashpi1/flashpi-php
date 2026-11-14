# Decisions

Decisions about flashpi/flashpi-php, the PHP SDK, newest first. Its design follows the Node.js
SDK, [@flashpi/node](https://github.com/flashpi1/flashpi-node), and the Python SDK,
[flashpi](https://github.com/flashpi1/flashpi-python), whose decisions
([Node](https://github.com/flashpi1/flashpi-node/blob/main/docs/decisions.md),
[Python](https://github.com/flashpi1/flashpi-python/blob/main/docs/decisions.md)) apply here
unless an entry says otherwise. Entries marked as the owner's were taken with the Flashpi product
owner; the others were chosen while porting, after the Python SDK where the owner had not decided,
and are listed so they can be confirmed or changed before 1.0.

## 2026-11-13: the PHP SDK

### The owner's decisions

- **Package and repository.** `flashpi/flashpi-php` on Packagist, namespace `Flashpi\`, public
  repository `flashpi1/flashpi-php`, MIT license held by SIGNIADIGITAL S.A. PHP 8.2 or later,
  tested on 8.2 to 8.5. First version 0.1.0 through release-please. Packagist reads the tags from
  GitHub: there is no upload or publish job, and the owner submits the repository to Packagist.
- **One runtime dependency.** Guzzle, `^7.8.2 || ^8.0` (Laravel 11 and 12 need 7, Laravel 13
  takes both), tested on both majors. A caller can pass a Guzzle client of their own
  (`GuzzleHttp\ClientInterface`).
- **Models are arrays.** Associative arrays with the API's camelCase keys
  (`$document['accessKey']`), typed by PHPStan array shapes generated from the OpenAPI document,
  the counterpart of Python's TypedDicts. No hydrated model classes. One synchronous client, no
  async. No Laravel integration here: a separate `flashpi/flashpi-laravel` comes later.
- **Tooling.** PHPUnit 11; PHPStan at the highest level over the library, the tests, the scripts
  and a consumer file; PHP-CS-Fixer with the PER-CS 2.0 rule set.
- **The Node and Python SDKs' design.** READMEs in Spanish (`README.md`) and English
  (`README.en.md`); resource style (`$flashpi->invoices->create($body)`,
  `$flashpi->documents->list([...])`, `$flashpi->issuers->establishments->create($issuerId,
  $body)`); the same retries, idempotency, recoveries, in-progress wait, deadline per attempt,
  header and API key validation, webhook verification, secrets kept out of every output, and
  release process (release-please from 0.1.0, the live API test on the release PR only, the
  flashpi-bot GitHub App for release-please and the OpenAPI sync PR, grouped Dependabot).

### Chosen while porting (to confirm)

- **The oldest dependencies.** `composer.json` conflicts with `guzzlehttp/promises` before 2.0.3
  and `guzzlehttp/psr7` before 2.6.3, the versions Guzzle 7.8.2 shipped with. Without it, the
  lowest versions `^7.8.2` allows are psr7 1.9 and promises 1.5, which emit deprecations on PHP
  8.2 and later from a certificate upload (dynamic properties of `MultipartStream`) and at
  autoload on 8.5. It adds no requirement but narrows what the owner's Guzzle constraint can
  install. Composer 2.9 and later also refuse, by default, versions with security advisories,
  among them every Guzzle 7 before 7.15.2: the CI job of the lowest versions turns that off
  (`audit.block-insecure`) and prints the versions it tested. Whether to raise the constraint to
  `^7.15.2 || ^8.0.1` (Laravel 11 and 12 accept it) is the owner's call.
- **Layout.** The client is `Flashpi\Flashpi`; next to it are `Page`, `ApiResponse`, `Webhooks`
  and `Version`. Exceptions are in `Flashpi\Exception`, types in `Flashpi\Types`, and the engine,
  the request specs and the bases of the resources in `Flashpi\Internal` (all `@internal`). Classes
  are final, except the bases of the exception hierarchy and of the resources. Every file declares
  strict types.
- **The client.** `new Flashpi(apiKey: ..., baseUrl: ..., timeout: ..., maxRetries: ...,
  language: ..., defaultHeaders: ..., httpClient: ...)`: named arguments, each null for its
  default (PHP cannot make them keyword-only). Timeouts are seconds as floats, as in Python. The
  client the SDK creates is a Guzzle client that follows no redirects; with a caller's client,
  every request still sets `allow_redirects: false`, `http_errors: false`, its timeouts and
  `expect: false`. `FLASHPI_API_KEY` is read from `$_ENV`, then `$_SERVER`, then `getenv()`, as
  dotenv libraries fill the first two. The User-Agent is `flashpi-php/<version>`.
- **Per-call options.** A last array argument with the keys of `RequestOptions` (`idempotencyKey`,
  `timeout`, `maxRetries`, `language`, `correlationId`, `extraHeaders`); null means the client's
  setting, and an unknown key is refused.
- **Exceptions.** `FlashpiException` (extends `\RuntimeException`) for Python's `FlashpiError`;
  `ApiException` with `BadRequestException`, `AuthenticationException`,
  `PermissionDeniedException`, `NotFoundException`, `ConflictException`,
  `UnprocessableEntityException`, `RateLimitException` and `InternalServerException`;
  `ConnectionException` and `TimeoutException` (Node's names: the namespace keeps them apart from
  PHP's); `WebhookVerificationException` with `->reason` and a constant per reason. Their fields
  are public read-only properties. The API's error code is `errorCode`, not `code`: PHP's
  `\Exception` already has a protected `$code` and a final `getCode()`, which returns the HTTP
  status here. `retryAfter()` is a method, since it reads the clock, and `header($name)` reads a
  response header in any letter case (`headers` holds them by lowercase name).
  `idempotencyKey` is the only property the SDK sets after creating the exception, so it is not
  read-only.
- **Misused arguments.** What PHP's native types catch throws a `\TypeError` (under strict
  types); what they cannot throws an `\InvalidArgumentException`: an unknown option (a
  `TypeError` in Python), an option, header name or header value that is not a string, a header
  value with a line break or another control character, a bad header name, `Content-Length` or
  `Transfer-Encoding`, a query value that is not a string, a number or a boolean, a body
  `json_encode()` refuses, and a webhook tolerance or time that is not finite (or a negative
  tolerance). Timeouts, `maxRetries`, ids, `baseUrl` and the API key stay `FlashpiException`, as
  in Node and Python.
- **Headers.** Python's rules, with two differences that come from PHP's strings being bytes:
  bytes that are not UTF-8 are not refused (PSR-7 sends them as obs-text), and there is no lone
  surrogate to refuse. As in Python, only `Content-Length` and `Transfer-Encoding` are refused; the
  Node SDK also refuses `Keep-Alive`, `Upgrade` and `Expect`. A request PSR-7 refuses to build
  throws a `FlashpiException` that is not retried, without PSR-7's reason, which quotes the
  refused value.
- **Transfer failures.** Every failure of a Guzzle transfer is retried, as a `TimeoutException`
  (Guzzle 8's timeout classes, curl's error 28 on Guzzle 7, or the attempt's deadline passed) or
  a `ConnectionException` whose message carries Guzzle's. Guzzle 7 and 8 classify failures
  differently, and Guzzle 8's stream handler reports a timeout while waiting for the headers as a
  plain `RequestException`, so the class cannot tell a refused request from a network failure as
  httpx's could. Guzzle's exception is never kept as the previous one: it holds the request,
  whose `Authorization` header carries the API key. Other throwables of the HTTP client (a bug
  in a middleware, an invalid Guzzle option) are not retried and not thrown as they are either,
  since the frames of their trace hold that request: an `\InvalidArgumentException` becomes a new
  one and anything else a `FlashpiException`, each with the original's class and message and no
  previous exception. Node wraps every failure of `fetch()` the same way; Python lets other
  errors through, but httpx's request hides the key. A caller's client that throws for error
  statuses (`BadResponseException`) gets its response read as the API's answer. An answer whose
  body ends before its `Content-Length` is a retried `ConnectionException` too, whatever the
  handler: curl and Guzzle 8's stream handler fail the transfer, and the SDK compares the length
  itself for Guzzle 7's stream handler, which returns what arrived (a body the handler decoded
  carries no `Content-Length` of its own and is not compared). httpx raises a network error
  there.
- **The deadline of an attempt.** Guzzle's `timeout`, `connect_timeout` and `read_timeout` are
  the attempt's timeout (at least one millisecond, Guzzle 8's floor). The curl handler limits the
  whole transfer on Guzzle 7 and 8. The SDK then reads the body itself, checking the deadline as
  each piece arrives and once the answer is read, so an answer whose end comes late fails with
  any handler. The stream handler (PHP without the curl extension, or a caller's client built on
  it) limits the headers only between packets, on both majors: headers that trickle in keep an
  attempt going past its timeout, and the answer is refused once they are complete. For the body,
  Guzzle 8's stream handler stops at the deadline, while Guzzle 7's limits each read only, so an
  answer that trickles in is refused once it has arrived. Python's client limits every network
  operation to the time left and has no such gap. `composer.json` suggests `ext-curl` for this;
  Guzzle stays the only dependency.
- **Bodies and answers.** A JSON body is encoded once with `JSON_UNESCAPED_SLASHES` and
  `JSON_UNESCAPED_UNICODE`, like `JSON.stringify()`; objects (`JsonSerializable`) are accepted. PHP
  has one empty array for an empty list and an empty object, so an empty array, at any depth, is
  sent as `[]` under the names the contract types as lists (`items`, `payments`, `taxes`, ...:
  `Engine::LIST_FIELDS`, checked against `openapi/openapi.json` by `tests/OperationsTest.php`) and
  as `{}` anywhere else (the body itself, `additionalInfo`, `options`, `branding`, ...). The API
  then answers an empty list as Python's SDK gets it answered (too short), and an empty object is
  valid. Answers are decoded into associative arrays, invalid UTF-8 replaced; a successful JSON
  answer without an array (empty, `null`, a string: a misbehaving proxy) throws a
  `FlashpiException`, where the native `: array` return type would throw a `\TypeError` (Python
  returns what it got). Text and bytes answers (XML, PDF) are the body as it came: PHP strings are
  bytes. The PSR-7 response an `ApiResponse` holds has its body rewound into a new stream, so it can
  be read again.
- **Queries and paths.** Null values are left out, booleans are sent as `true` and `false`,
  numbers and `Stringable` values as text, and anything else is refused; the encoding is
  Python's `urlencode()` (a space as `+`). Path ids are encoded as `encodeURIComponent()` does;
  ids that JavaScript's `trim()` leaves empty (spaces, line breaks, a form feed, a no-break
  space, a byte order mark), as in Node, and `.` and `..` are refused before anything is sent.
- **withResponse().** Each resource's `withResponse()` returns a view
  (`$flashpi->documents->withResponse()->get($id)`) whose methods are declared with `@method`
  tags: it runs the resource's method and returns an `ApiResponse` with its data, the PSR-7
  response the method got and the request id. A method that sends nothing throws a
  `FlashpiException`, and a name the resource lacks a `\BadMethodCallException`. Each call runs
  on a copy of the resource with a recorder of its own (Python's context variable), so calls
  that interleave on one client in fibers (an event loop's Guzzle handler) never read each
  other's response.
- **Resources.** One final class per resource in `Flashpi\Resources`, with its view next to it
  (`DocumentsWithResponse`). The client's properties are Python's names in camelCase:
  `invoices`, `creditNotes`, `debitNotes`, `purchaseSettlements`, `waybills`, `withholdings`,
  `documents`, `issuers` (with `establishments`, whose `emissionPoints`, and `certificates`),
  `issuerOnboardings`, `webhookEndpoints` (with `deliveries`), `events` and `status`; methods
  too (`documents->sendEmail()`, `webhookEndpoints->getSecret()` and `rotateSecret()`). The
  status resource's class is `StatusResource`, the name Node exports it with, since `Status` is
  the type of its answer. Each document type is a class of its own rather than one generic
  `DocumentIssuer`, so each `create()` declares its own body without PHPStan generics, which
  IDEs read poorly.
- **Method signatures.** The path ids first, as strings, in the order of the path; then the
  body, the filters (`?array $params = null`) or the upload; then `array $options = []`. Bodies
  are native arrays typed by their generated shape, so an object body is a `\TypeError` (Python
  sends any body as it is; a list is still sent as a JSON array). A body the contract makes
  optional is `?array $body = null`: `issuerOnboardings->complete()` then sends `{}` and
  `webhookEndpoints->rotateSecret()` no body, as in Python. `documents->sendEmail()` always
  sends `{}`; `webhookEndpoints->verify()` and `deliveries->replay()` send no body. A DELETE
  returns `void` (its view's `data` is null), `documents->pdf()` the RIDE's bytes as a string
  and `documents->xml()` a `DocumentXml` array, its `kind` from the `Flashpi-Xml-Kind` header.
- **Certificate uploads.** A `CertificateUpload` (SdkTypes) is an array with `file`,
  `password` and an optional `filename`. `file` is the .p12's contents as a string, a stream
  opened for reading (`fopen('firma.p12', 'rb')`) or a PSR-7 stream: PHP strings are bytes, so a
  string is always the contents, never a path. A stream is read from where it stands, in pieces,
  up to one byte past 1 MiB, before anything is sent. As in Python, a password that is not a
  string, an empty file, one over 1 MiB, a non-blocking stream with no data ready and a read
  that fails or times out throw a `FlashpiException`; a file of another type, a stream opened
  for writing only, a PSR-7 stream that cannot be read and a file name that is not a string
  throw an `\InvalidArgumentException` (Python's `TypeError`). A PSR-7 stream's own exception is
  thrown as it is, as Python lets a file's `OSError` through. Unknown keys are not refused at run
  time, as in Python; PHPStan's sealed shapes report them. The file part's
  `Content-Disposition` is written by the SDK, its file name sent whole and encoded as HTML
  forms and httpx do (with DEL and ESC encoded too, which psr7 3 refuses in a header): Guzzle
  would keep only the base name, and psr7 2 would not escape it.
- **Tests of the contract.** `tests/OperationsTest.php` reads `openapi/openapi.json` and checks
  that its 45 operations map one to one onto the resources' methods, with the contract's ids,
  filters, bodies and answers, that each view declares the same methods, and that each method
  sends its operation. `tests/Types/Fixtures/ResourcesConsumer.php` uses every resource as the
  README does and passes PHPStan at level max; `Invalid/ResourcesMisuse.php` holds the mistakes
  PHPStan must report. `tests/Types/ConsumerTypesTest.php` analyses both with a consumer's
  configuration (`tests/Types/Fixtures/phpstan.neon`: level max and bleeding edge only), since
  the repository's own flags (`treatPhpDocTypesAsCertain: false` among them) hide errors a reader
  would get.
- **README examples.** As in Python, every `php` block of both READMEs is checked:
  `scripts/readme.php` (PHP alone, `composer readme:generate|check`) writes each one to
  `tests/Examples/Readme/{es,en}_NN.php`, in a namespace of its own, with the variables it reads
  before setting them (`$flashpi`, `$invoice`, `$body`, ...) taken from
  `tests/Examples/Readme/Context.php`. PHPStan analyses them with the consumer's configuration of
  `tests/Types/ConsumerTypesTest.php`, with stubs of the bits of Laravel and Symfony they use
  (`tests/Examples/stubs/`, neither framework is a dependency), and PHP-CS-Fixer checks their
  style. The webhook examples are for plain PHP, Laravel and Symfony, where Python's are for
  Django, Flask and FastAPI.
- **Live tests.** `tests/Live` is the PHPUnit group `live`, which `phpunit.xml.dist` leaves out;
  `composer test:live` runs it. The Python suite's async-client cases run on the one client.
- **Pages.** `list()` returns a `Page`: `foreach` walks every item across pages (keys continue
  from page to page), and `data`, `hasMore`, `nextCursor`, `hasNextPage()` and `nextPage()` move
  one page at a time. Walking a page twice fetches the following pages again.
- **Webhooks.** `Webhooks` has `unwrap()` and `verifySignature()` (also `$flashpi->webhooks`), and
  the client has the static `Flashpi::unwrapWebhook()` and `Flashpi::verifyWebhookSignature()`: PHP
  cannot share one name between a static and an instance method, and calling a static method on an
  instance is refused by PHPStan's strict rules. As in Python, a payload that is not a string (or a
  `Stringable`, such as a PSR-7 body) is `invalid_payload`, and a secret that is not a string
  (`getenv()` returning false) is `invalid_secret`, rather than a `\TypeError`, so a receiver
  answers 400. The secret is trimmed as the Node SDK's `trim()` trims it (Unicode spaces and the
  byte order mark; Python's `strip()` keeps the mark). Headers are any iterable (arrays,
  `getallheaders()`, PSR-7's `getHeaders()`, Symfony's `HeaderBag`, a generator), read once, and
  typed `iterable<mixed, mixed>` (`WebhookHeaders`) so that PHPStan at level max accepts what
  servers hand over; entries that are not strings are skipped. With Guzzle 7, psr7 2 installs a
  `getallheaders()` polyfill (ralouphie/getallheaders) without a return type, which PHPStan reads
  as `mixed`: there, a caller at level max writes `(array) getallheaders()`, and
  `tests/Types/ConsumerTypesTest.php` runs with Guzzle 8 only. `now` is a date or Unix seconds. A
  `Stringable` payload is read once too: `unwrap()` decodes the bytes it verified, so a PSR-7 body
  that cannot seek works, and one that would read otherwise a second time cannot slip in unverified
  JSON.
- **Secrets.** The API key lives in a `\SensitiveParameterValue` inside the client's configuration,
  and a certificate upload's parts in one inside its multipart body. It shows nothing of what it
  holds, so `var_dump()`, `print_r()`, `var_export()`, a cast to array followed by any of them and
  Symfony's VarDumper (`dump()`, `dd()`, Laravel's Tinker) never show them, and `serialize()` fails
  (serializing a client fails). A closure would not do: its captured variables show in `var_dump()`
  of a cast and in VarDumper. `withResponse()` passes the call's arguments through no closure
  either (see above). `__debugInfo()` shows only what is safe, and every parameter that holds the
  key, a webhook secret or its key, a .p12, its password, a Guzzle request or a Guzzle exception is
  marked `#[\SensitiveParameter]`, so stack traces hide it. PHP's traces carry arguments, not local
  variables, so the Python SDK's work on frame locals has no counterpart.
- **Types.** A generator of our own (`scripts/openapi.php`, PHP alone) writes one class,
  `Flashpi\Types\ApiTypes`, with a `@phpstan-type` per schema and per list query, named as in
  Python; hand-written types are in `Flashpi\Types\SdkTypes`. `number` is `int|float`,
  `additionalProperties: true` is `...<string, mixed>`, and every reference to `ErrorCode` or
  `FieldErrorCode` accepts any string (Python opens them in hand-written types only). PHPDoc
  aliases carry no description per member. PHPStan 2 seals array shapes only with its bleeding
  edge configuration: without it, a misspelled optional key is not reported. This repository
  enables it, and the READMEs' "Static analysis" section tells consumers to.
- **Coverage.** pcov only measures `src/` unless told otherwise: CI passes
  `-d pcov.directory=.` for the scripts' classes to count, and fails below 95 % of lines, as
  Python's `fail_under`. The command-line entry points of `scripts/` only read their arguments
  and call those classes: `phpunit.xml.dist` leaves them out, and CI runs them as steps.
- **CI.** `ci.yml` has Python's jobs: verify on PHP 8.5 with Guzzle 8 (`composer validate
  --strict`, the OpenAPI and README checks, PHP-CS-Fixer, PHPStan, PHPUnit with coverage); the
  tests on PHP 8.2 to 8.5 with Guzzle 7 and 8, PHPStan over `src` and `scripts` only with Guzzle
  7 (its psr7 lacks Guzzle 8's generics the test helpers use); the lowest versions on PHP 8.2
  only (PHPUnit 11.5.0 prints a deprecation of its own on 8.5); and the archive, checked by
  `scripts/package_check.php`, then installed in a clean project with each Guzzle major on PHP
  8.2 and 8.5 (`scripts/installed_check.php`). `live.yml`, `openapi-sync.yml`, `release.yml` and
  `dependabot.yml` follow Python's, without a build or publish job: Packagist reads the tags.
  PHP comes from `shivammathur/setup-php`, pinned by commit like every action.
