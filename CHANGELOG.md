# Changelog

## [0.2.0](https://github.com/flashpi1/flashpi-php/compare/v0.1.0...v0.2.0) (2026-11-17)


### ⚠ BREAKING CHANGES

* PHP 8.2 is no longer supported; 0.1.x stays available for it.

### Features

* require PHP 8.3 ([c468b80](https://github.com/flashpi1/flashpi-php/commit/c468b8075133067d3645783da0dc664a1986eff2))

## 0.1.0 (2026-11-14)


### Features

* add issuer onboardings ([310896e](https://github.com/flashpi1/flashpi-php/commit/310896e00d2e347a0357b9b2b3a54a0267647d5f))
* add issuers with establishments, emission points and certificates ([9b9de16](https://github.com/flashpi1/flashpi-php/commit/9b9de162364a901758fa57bd23c020175d5b3a69))
* add the client and the request engine ([94fe894](https://github.com/flashpi1/flashpi-php/commit/94fe894d8ced9ae9b958ee7b5b621ddd4ce7aef1))
* add the exceptions ([7cfed85](https://github.com/flashpi1/flashpi-php/commit/7cfed853698ba1c42d647b98df865197735ef7b4))
* add the SDK's own types and prove them on consumer code ([fae6385](https://github.com/flashpi1/flashpi-php/commit/fae6385b72243d993c67c1e7b5f41b52f8d81478))
* add the status and events resources ([5db37da](https://github.com/flashpi1/flashpi-php/commit/5db37da8bc67699a31844e6c80707f27b0688e6b))
* add webhook endpoints and their deliveries ([5d18ee0](https://github.com/flashpi1/flashpi-php/commit/5d18ee03509acc3605ace4aa188aebf923d3e67b))
* generate PHPStan types from the OpenAPI contract ([d94def1](https://github.com/flashpi1/flashpi-php/commit/d94def170acca8c369315bbb6cf9988fcc6f3398))
* issue the six document types ([a5778fa](https://github.com/flashpi1/flashpi-php/commit/a5778fa63edd5f46e9bd66e19f60cb4b47821aa5))
* search documents and read their XML, RIDE and email ([5565bca](https://github.com/flashpi1/flashpi-php/commit/5565bca4f98223b41fa1413676a76e127a67fcfe))
* the PHP SDK ([81c35d4](https://github.com/flashpi1/flashpi-php/commit/81c35d4398833d56ca4d301a9ba33f357068e2db))
* verify the webhooks Flashpi sends ([055febe](https://github.com/flashpi1/flashpi-php/commit/055febe100235829e991b25c8838dffb6c822440))


### Bug fixes

* accept the webhook headers PSR-7 and getallheaders() hand over ([dc72119](https://github.com/flashpi1/flashpi-php/commit/dc72119fb68f3a9fcf6c03320b5e5015f3534676))
* give the extra members of an open shape string keys ([6464881](https://github.com/flashpi1/flashpi-php/commit/64648817efb70219694c3d3d105d9b0467fca485))
* keep the API key and a .p12 in a SensitiveParameterValue ([6fc87c7](https://github.com/flashpi1/flashpi-php/commit/6fc87c7f5e1d4b8ea611c987631456d76683d7ab))
* keep the HTTP client's other errors and their trace out ([5a657d0](https://github.com/flashpi1/flashpi-php/commit/5a657d0cd353148f93ae4c963b354abb2f3e1af1))
* keep the idempotency key on a 2xx answer without a JSON object ([a6f18ac](https://github.com/flashpi1/flashpi-php/commit/a6f18aceda792ebeb83cb5ed2979335b01c1c5fd))
* name Guzzle 8's timeout exceptions by string ([64ad45e](https://github.com/flashpi1/flashpi-php/commit/64ad45efc4704e6c0ffd55e78b6345c26e2c7ab2))
* read a Retry-After date whose day has one digit ([80de2ec](https://github.com/flashpi1/flashpi-php/commit/80de2ecf1f56b9de46bbc0fe001fc7b43ab98946))
* read a webhook body once in unwrap() ([9b2c386](https://github.com/flashpi1/flashpi-php/commit/9b2c386351ec59e6da59c67903d49728caed3a90))
* record each withResponse() call's response on its own ([3125c10](https://github.com/flashpi1/flashpi-php/commit/3125c102191230307e9856b97686270d40431fa3))
* refuse a header name or a webhook timestamp ending in a line break ([dd4b12c](https://github.com/flashpi1/flashpi-php/commit/dd4b12cea29e3d53c0879e892911e4661aea7105))
* refuse a JSON answer without an array as a FlashpiException ([97d9942](https://github.com/flashpi1/flashpi-php/commit/97d9942a8ca46f6837292a75bfcc4958fb6bebe6))
* refuse contract strings that would end a PHPDoc comment ([fbf3953](https://github.com/flashpi1/flashpi-php/commit/fbf395308da1236a8999d86bee86f9db29c4dac8))
* refuse ids that JavaScript's trim() leaves blank ([6bb1733](https://github.com/flashpi1/flashpi-php/commit/6bb1733349cf862f13008e46cf5c936b6d762cae))
* refuse the Keep-Alive, Upgrade and Expect headers ([5219128](https://github.com/flashpi1/flashpi-php/commit/5219128841359a814c745979209c2f1258abb39b))
* retry an answer that ends before its Content-Length ([0c8bb10](https://github.com/flashpi1/flashpi-php/commit/0c8bb10002589b225b5653886b46ca1a47a9873a))
* round the read timeout up to whole seconds ([4cb9786](https://github.com/flashpi1/flashpi-php/commit/4cb97867e2c854010b6144168070b9aa662e70ff))
* run the live suite without Composer's process timeout ([4ffeb59](https://github.com/flashpi1/flashpi-php/commit/4ffeb59f8117b78ce80b3f9fc5f5d81c08c707ae))
* send a nested empty array as an object, except the contract's lists ([48f7fad](https://github.com/flashpi1/flashpi-php/commit/48f7fad364a6aa23b62a002da1803df93dcc0c2d))
* trim a webhook secret as JavaScript's trim() does ([5ace5eb](https://github.com/flashpi1/flashpi-php/commit/5ace5eb1131b94eb313d918bf72f124b962233d0))
* write the file name of a multipart part the same way on every Guzzle ([841bedd](https://github.com/flashpi1/flashpi-php/commit/841bedd6e6a736437a58c7936375fb4d76ccf035))
