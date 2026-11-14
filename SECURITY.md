# Security

Please do not report security problems in public issues.

Report them privately from this repository's **Security** tab with **Report a vulnerability**
(GitHub private vulnerability reporting). Only the Flashpi maintainers see the report. Include
the version of `flashpi/flashpi-php`, your PHP and Guzzle versions and the steps to reproduce.

For problems with the Flashpi API itself, use the same channel: we route them to the API team.

## Keeping your integration safe

- Your API key (`fp_live_...`) is a secret: keep it on your server, in an environment variable
  such as `FLASHPI_API_KEY`, never in code that runs on your users' devices. The SDK never shows
  it: not in `var_dump()`, `print_r()`, `var_export()`, Symfony's `dump()`, exceptions or stack
  traces.
- Verify every webhook with `Flashpi::unwrapWebhook()` (or `$flashpi->webhooks->unwrap()`) on
  the raw body before you act on it, and deduplicate on its `id`.
- Install the curl extension in production: it enforces each attempt's timeout over the whole
  answer.
