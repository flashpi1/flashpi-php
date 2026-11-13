<?php

declare(strict_types=1);

namespace Flashpi\Tests;

use Flashpi\Flashpi;
use Flashpi\Internal\Resource;
use Flashpi\Internal\ResponseView;
use Flashpi\Page;
use Flashpi\Tests\Support\Contract;
use Flashpi\Tests\Support\MockApi;
use Flashpi\Tests\Support\Operation;
use Flashpi\Tests\Support\Replies;
use Flashpi\Tests\Support\TestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Every operation of the API's OpenAPI document maps to exactly one method of a resource, which
 * sends its HTTP method, path, query parameters and body, and declares its answer with the
 * generated type (Python: tests/test_operations.py).
 */
final class OperationsTest extends TestCase
{
    /**
     * Each operationId of the contract and the resource method that sends it.
     */
    private const OPERATIONS = [
        'statusGet' => 'status.get',
        'issuersCreate' => 'issuers.create',
        'issuersList' => 'issuers.list',
        'issuersGet' => 'issuers.get',
        'issuersUpdate' => 'issuers.update',
        'issuersDelete' => 'issuers.delete',
        'establishmentsCreate' => 'issuers.establishments.create',
        'establishmentsList' => 'issuers.establishments.list',
        'establishmentsGet' => 'issuers.establishments.get',
        'establishmentsUpdate' => 'issuers.establishments.update',
        'establishmentsDelete' => 'issuers.establishments.delete',
        'emissionPointsCreate' => 'issuers.establishments.emissionPoints.create',
        'emissionPointsList' => 'issuers.establishments.emissionPoints.list',
        'emissionPointsGet' => 'issuers.establishments.emissionPoints.get',
        'emissionPointsDelete' => 'issuers.establishments.emissionPoints.delete',
        'certificatesUpload' => 'issuers.certificates.upload',
        'certificatesList' => 'issuers.certificates.list',
        'certificatesGet' => 'issuers.certificates.get',
        'issuerOnboardingsCreate' => 'issuerOnboardings.create',
        'issuerOnboardingsGet' => 'issuerOnboardings.get',
        'issuerOnboardingsComplete' => 'issuerOnboardings.complete',
        'invoicesCreate' => 'invoices.create',
        'creditNotesCreate' => 'creditNotes.create',
        'debitNotesCreate' => 'debitNotes.create',
        'purchaseSettlementsCreate' => 'purchaseSettlements.create',
        'waybillsCreate' => 'waybills.create',
        'withholdingsCreate' => 'withholdings.create',
        'documentsList' => 'documents.list',
        'documentsGet' => 'documents.get',
        'documentsGetXml' => 'documents.xml',
        'documentsGetPdf' => 'documents.pdf',
        'documentsSendEmail' => 'documents.sendEmail',
        'webhookEndpointsCreate' => 'webhookEndpoints.create',
        'webhookEndpointsList' => 'webhookEndpoints.list',
        'webhookEndpointsGet' => 'webhookEndpoints.get',
        'webhookEndpointsUpdate' => 'webhookEndpoints.update',
        'webhookEndpointsDelete' => 'webhookEndpoints.delete',
        'webhookEndpointsSecret' => 'webhookEndpoints.getSecret',
        'webhookEndpointsRotateSecret' => 'webhookEndpoints.rotateSecret',
        'webhookEndpointsVerify' => 'webhookEndpoints.verify',
        'webhookEndpointsDeliveriesList' => 'webhookEndpoints.deliveries.list',
        'webhookEndpointsDeliveriesGet' => 'webhookEndpoints.deliveries.get',
        'webhookEndpointsDeliveriesReplay' => 'webhookEndpoints.deliveries.replay',
        'eventsList' => 'events.list',
        'eventsGet' => 'events.get',
    ];

    /**
     * Operations whose body the contract requires but whose method may leave out: `{}` is a valid
     * body (completing the renewal of an existing issuer), as in the Node and Python SDKs.
     */
    private const EMPTY_BODY_ALLOWED = ['issuerOnboardingsComplete'];

    private const P12 = "\x30\x82\x01\x02";

    public function testMapsEveryOperationOfTheContractToItsOwnMethod(): void
    {
        $ids = array_keys(Contract::operations());
        sort($ids);
        $mapped = array_keys(self::OPERATIONS);
        sort($mapped);
        self::assertSame($ids, $mapped);
        self::assertCount(45, $ids);
        self::assertSame(array_values(self::OPERATIONS), array_values(array_unique(self::OPERATIONS)));
    }

    public function testEveryMethodOfTheResourcesIsOneOperation(): void
    {
        $methods = [];
        foreach (self::resources(self::client()) as $name => $resource) {
            self::assertTrue((new \ReflectionClass($resource))->isFinal(), $resource::class . ' is not final');
            foreach (self::publicMethods($resource) as $method) {
                $methods[] = "{$name}.{$method->getName()}";
            }
        }
        sort($methods);
        $expected = array_values(self::OPERATIONS);
        sort($expected);
        self::assertSame($expected, $methods);
    }

    public function testEveryResourceHasAWithResponseViewOfItsMethods(): void
    {
        foreach (self::resources(self::client()) as $name => $resource) {
            $view = (new \ReflectionMethod($resource, 'withResponse'))->invoke($resource);
            self::assertInstanceOf(ResponseView::class, $view, $name);
            $class = new \ReflectionClass($view);
            self::assertTrue($class->isFinal(), $class->getName());
            self::assertSame($resource::class . 'WithResponse', $class->getName());
            $expected = array_map(
                static fn(\ReflectionMethod $method): string => self::viewTag($method),
                self::publicMethods($resource),
            );
            preg_match_all('/^ \* @method (.+)$/m', (string) $class->getDocComment(), $tags);
            self::assertSame($expected, $tags[1], $name);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function operationIds(): iterable
    {
        foreach (array_keys(self::OPERATIONS) as $id) {
            yield $id => [$id];
        }
    }

    #[DataProvider('operationIds')]
    public function testTheSignatureFollowsTheContract(string $id): void
    {
        $operation = Contract::operation($id);
        $method = self::method(self::client(), self::methodName($id));
        $types = self::paramTypes($method);
        $parameters = $method->getParameters();

        // The path ids in order, then the body, the filters or the upload, then the options.
        $options = array_pop($parameters);
        self::assertNotNull($options);
        self::assertSame('options', $options->getName());
        self::assertSame('array', (string) $options->getType());
        self::assertSame([], $options->getDefaultValue());
        self::assertSame('RequestOptions', $types['options'] ?? null);
        preg_match_all('/\{(\w+)\}/', $operation->path, $placeholders);
        $ids = array_values(array_filter($parameters, static fn(\ReflectionParameter $parameter): bool => str_ends_with($parameter->getName(), 'Id')));
        self::assertSame($placeholders[1], array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $ids));
        foreach ($ids as $parameter) {
            self::assertSame('string', (string) $parameter->getType());
            self::assertFalse($parameter->isOptional());
        }
        $others = array_values(array_filter($parameters, static fn(\ReflectionParameter $parameter): bool => !\in_array($parameter, $ids, true)));
        $names = array_map(static fn(\ReflectionParameter $parameter): string => $parameter->getName(), $others);
        $first = $others[0] ?? null;

        if ($operation->query !== []) {
            self::assertSame(['params'], $names);
            self::assertNotNull($first);
            self::assertSame('?array', (string) $first->getType());
            self::assertNull($first->getDefaultValue());
            self::assertMatchesRegularExpression('/^\w+ListParams\|null$/', $types['params'] ?? '');
            $alias = self::sdkAliases()[substr($types['params'] ?? '', 0, -5)] ?? null;
            self::assertSame(ucfirst($id) . 'Query', $alias);
            $keys = self::shapeKeys($alias);
            sort($keys);
            $query = array_keys($operation->query);
            sort($query);
            self::assertSame($query, $keys);
        } elseif ($operation->bodyType === 'multipart/form-data') {
            self::assertSame(['upload'], $names);
            self::assertNotNull($first);
            self::assertSame('CertificateUpload', $types['upload'] ?? null);
            self::assertCount(1, $first->getAttributes(\SensitiveParameter::class));
        } elseif ($operation->bodyType !== null && $others !== []) {
            self::assertSame(['body'], $names);
            self::assertNotNull($first);
            $optional = !$operation->bodyRequired || \in_array($id, self::EMPTY_BODY_ALLOWED, true);
            self::assertSame($operation->bodySchema . ($optional ? '|null' : ''), $types['body'] ?? null);
            self::assertSame($optional ? '?array' : 'array', (string) $first->getType());
            self::assertSame($optional, $first->isOptional());
        } else {
            // documents->sendEmail(): its body has no members yet, so the method always sends {}.
            self::assertSame([], $others);
            self::assertTrue($operation->bodyType === null || !$operation->bodyRequired);
        }

        // The answer, as its generated type.
        $returned = self::returnType($method);
        if ($operation->answerType === null) {
            self::assertSame('void', $returned);
        } elseif ($operation->answerType === 'application/xml') {
            self::assertSame('DocumentXml', $returned);
        } elseif ($operation->answerType === 'application/pdf') {
            self::assertSame('string', $returned);
        } elseif ($operation->itemSchema !== null) {
            self::assertSame(Page::class, (string) $method->getReturnType());
            self::assertSame("Page<{$operation->itemSchema}>", $returned);
        } else {
            self::assertSame('array', (string) $method->getReturnType());
            self::assertSame($operation->answerSchema, $returned);
        }
    }

    #[DataProvider('operationIds')]
    public function testSendsTheOperationOfTheContract(string $id): void
    {
        $operation = Contract::operation($id);
        $api = self::api([self::answer($operation)]);
        $arguments = [];
        $path = $operation->path;
        $query = [];
        $parameters = self::method($api->client, self::methodName($id))->getParameters();
        array_pop($parameters);
        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            if (str_ends_with($name, 'Id')) {
                $value = 'id' . (\count($arguments) + 1);
                $path = (string) preg_replace('/\{\w+\}/', $value, $path, 1);
                $arguments[] = $value;
            } elseif ($name === 'params') {
                $query = array_map(static fn(array $schema): string|int => self::sample($schema), $operation->query);
                $arguments[] = $query;
            } elseif ($name === 'upload') {
                $arguments[] = ['file' => self::P12, 'password' => 'x'];
            } else {
                self::assertSame('body', $name);
                $arguments[] = [];
            }
        }
        $result = $api->op(self::methodName($id), ...$arguments);

        self::assertCount(1, $api->sent);
        $request = $api->request(0);
        self::assertSame($operation->method, $request->getMethod());
        self::assertSame($path, $request->getUri()->getPath());
        self::assertSame(array_map(static fn(string|int $value): string => (string) $value, $query), self::queries($api)[0] ?? null);
        $contentType = $request->getHeaderLine('content-type');
        if ($operation->bodyType === null) {
            self::assertSame(['', ''], [(string) $request->getBody(), $contentType]);
        } elseif ($operation->bodyType === 'multipart/form-data') {
            self::assertStringStartsWith('multipart/form-data; boundary=', $contentType);
        } else {
            self::assertSame($operation->bodyType, $contentType);
            self::assertSame('{}', (string) $request->getBody());
        }
        self::assertSame($operation->answerType ?? 'application/json', explode(',', $request->getHeaderLine('accept'))[0]);
        self::assertSame($operation->idempotentPost, $request->hasHeader('idempotency-key'));
        self::assertSame($operation->method === 'POST', $operation->idempotentPost);
        if ($operation->answerType === null) {
            self::assertNull($result);
        } elseif ($operation->itemSchema !== null) {
            self::assertInstanceOf(Page::class, $result);
        }
    }

    private static function methodName(string $id): string
    {
        $name = self::OPERATIONS[$id] ?? null;
        self::assertNotNull($name, "No method for {$id}");

        return $name;
    }

    private static function client(): Flashpi
    {
        return new Flashpi(Replies::TEST_KEY);
    }

    /**
     * Every resource of a client (or of a resource), by dotted name.
     *
     * @return array<string, Resource>
     */
    private static function resources(object $parent, string $prefix = ''): array
    {
        $found = [];
        foreach ((new \ReflectionObject($parent))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $value = $property->getValue($parent);
            if ($value instanceof Resource) {
                $found[$prefix . $property->getName()] = $value;
                $found = [...$found, ...self::resources($value, $prefix . $property->getName() . '.')];
            }
        }

        return $found;
    }

    /**
     * The operations of a resource: its own public methods, without withResponse().
     *
     * @return list<\ReflectionMethod>
     */
    private static function publicMethods(Resource $resource): array
    {
        return array_values(array_filter(
            (new \ReflectionObject($resource))->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn(\ReflectionMethod $method): bool => $method->getDeclaringClass()->getName() === $resource::class
                && !str_starts_with($method->getName(), '__')
                && $method->getName() !== 'withResponse',
        ));
    }

    private static function method(Flashpi $client, string $name): \ReflectionMethod
    {
        [$resource, $method] = MockApi::resolve($client, $name);

        return new \ReflectionMethod($resource, $method);
    }

    /**
     * The PHPDoc type of each parameter, or its native type when it has no `@param` tag.
     *
     * @return array<string, string>
     */
    private static function paramTypes(\ReflectionMethod $method): array
    {
        $types = [];
        foreach ($method->getParameters() as $parameter) {
            $types[$parameter->getName()] = (string) $parameter->getType();
        }
        preg_match_all('/@param (\S+) \$(\w+)/', (string) $method->getDocComment(), $tags, \PREG_SET_ORDER);
        foreach ($tags as $tag) {
            $types[$tag[2]] = $tag[1];
        }

        return $types;
    }

    /**
     * The PHPDoc return type, or the native one when there is no `@return` tag.
     */
    private static function returnType(\ReflectionMethod $method): string
    {
        return preg_match('/@return (\S+)/', (string) $method->getDocComment(), $tag) === 1
            ? $tag[1]
            : (string) $method->getReturnType();
    }

    /**
     * The `@method` tag the withResponse() view must have for a method.
     */
    private static function viewTag(\ReflectionMethod $method): string
    {
        $types = self::paramTypes($method);
        $parameters = array_map(
            static fn(\ReflectionParameter $parameter): string => ($types[$parameter->getName()] ?? '') . ' $' . $parameter->getName()
                . ($parameter->isOptional() ? ' = ' . ($parameter->getDefaultValue() === null ? 'null' : '[]') : ''),
            $method->getParameters(),
        );
        $returned = self::returnType($method);

        return 'ApiResponse<' . ($returned === 'void' ? 'null' : $returned) . "> {$method->getName()}(" . implode(', ', $parameters) . ')';
    }

    /**
     * The `*ListParams` aliases of SdkTypes and the generated type each one names.
     *
     * @return array<string, string>
     */
    private static function sdkAliases(): array
    {
        preg_match_all('/@phpstan-type (\w+ListParams) (\w+)$/m', (string) file_get_contents(__DIR__ . '/../src/Types/SdkTypes.php'), $aliases);

        return array_combine($aliases[1], $aliases[2]);
    }

    /**
     * The keys of a flat array shape of ApiTypes.
     *
     * @return list<string>
     */
    private static function shapeKeys(string $alias): array
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Types/ApiTypes.php');
        self::assertSame(1, preg_match('/@phpstan-type ' . $alias . ' array\{\n(.*?)\n \* \}/s', $source, $shape));
        preg_match_all('/^ \*     (\w+)\??:/m', $shape[1] ?? '', $keys);

        return $keys[1];
    }

    /**
     * A valid query value for a schema.
     *
     * @param array<mixed> $schema
     */
    private static function sample(array $schema): string|int
    {
        $values = $schema['enum'] ?? null;
        if (\is_array($values) && \is_string($values[0] ?? null)) {
            return $values[0];
        }

        return ($schema['type'] ?? null) === 'integer' ? 7 : 'v';
    }

    private static function answer(Operation $operation): Response
    {
        return match (true) {
            $operation->answerType === null => new Response($operation->status),
            $operation->answerType === 'application/xml' => new Response($operation->status, ['Content-Type' => 'application/xml'], '<x/>'),
            $operation->answerType === 'application/pdf' => new Response($operation->status, ['Content-Type' => 'application/pdf'], '%PDF-'),
            $operation->query !== [] => Replies::page([]),
            default => Replies::json([], $operation->status),
        };
    }
}
