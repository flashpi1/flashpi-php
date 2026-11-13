<?php

declare(strict_types=1);

namespace Flashpi\Tests\Support;

use Flashpi\ApiResponse;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;

/**
 * The withResponse() view of the test resource, declared as a real resource declares its own.
 *
 * @method ApiResponse<mixed> get(string $id, array<mixed> $options = [])
 * @method ApiResponse<mixed> issue(mixed $body, array<mixed> $options = [])
 * @method ApiResponse<mixed> delete(string $id, array<mixed> $options = [])
 * @method ApiResponse<Page<mixed>> list(array<string, mixed>|null $query = null, array<mixed> $options = [])
 * @method ApiResponse<int> nothing()
 */
final class ThingsWithResponse extends ResponseView {}
