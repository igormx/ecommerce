<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Product;

use App\Application\Actions\ActionError;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;
use Tests\Support\InMemoryProductRepository;
use Tests\TestCase;

class ProductWriteEndpointsTest extends TestCase
{
    private InMemoryProductRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new InMemoryProductRepository(
            $this->product(1, 'HAM-1', 'Hammer'),
            $this->product(2, 'SAW-1', 'Saw'),
            $this->product(3, 'OLD-1', 'Retired', false),
        );
    }

    private function product(?int $id, string $sku, string $name, bool $active = true): Product
    {
        return new Product(
            $id,
            $sku,
            $name,
            'A fine ' . $name,
            'Tools',
            1999,
            5,
            1.5,
            $active,
            new DateTimeImmutable('2024-01-01T10:00:00+00:00'),
            new DateTimeImmutable('2024-01-02T11:30:00+00:00'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validBody(): array
    {
        return [
            'sku' => 'NEW-1',
            'name' => 'Wrench',
            'description' => 'Adjustable',
            'category' => 'Tools',
            'priceCents' => 2500,
            'stock' => 10,
            'weightKg' => 0.75,
        ];
    }

    /**
     * @return array<string, mixed> validBody() plus the `version` an edit form would have loaded
     */
    private function updateBody(int $version = 1): array
    {
        return $this->validBody() + ['version' => $version];
    }

    /**
     * @param array<array-key, mixed>|null $body
     * @return array{status: int, body: array, raw: string}
     */
    private function send(string $method, string $path, ?array $body = null): array
    {
        $app = $this->getAppInstance([ProductRepositoryInterface::class => $this->repo]);
        $request = $this->createRequest($method, $path);
        if ($body !== null) {
            $request = $request->withParsedBody($body);
        }

        return $this->handle($app, $request);
    }

    /**
     * @return array{status: int, body: array, raw: string}
     */
    private function sendRaw(string $method, string $path, string $rawBody): array
    {
        $app = $this->getAppInstance([ProductRepositoryInterface::class => $this->repo]);
        $request = $this->createRequest($method, $path, [
            'HTTP_ACCEPT' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $request->getBody()->write($rawBody);
        $request->getBody()->rewind();

        return $this->handle($app, $request);
    }

    /**
     * @return array{status: int, body: array, raw: string}
     */
    private function handle(\Slim\App $app, \Psr\Http\Message\ServerRequestInterface $request): array
    {
        $response = $app->handle($request);
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : [], 'raw' => $raw];
    }

    public function testCreateReturns201WithProduct(): void
    {
        $result = $this->send('POST', '/products', $this->validBody());

        $this->assertSame(201, $result['status']);
        $data = $result['body']['data'];
        $this->assertSame('NEW-1', $data['sku']);
        $this->assertSame('Wrench', $data['name']);
        $this->assertSame(2500, $data['priceCents']);
        $this->assertSame(10, $data['stock']);
        $this->assertSame(0.75, $data['weightKg']);
        $this->assertSame('NEW-1', $this->repo->findById($data['id'])->sku);
    }

    public function testCreateAcceptsMinimalBodyAndTrimsStrings(): void
    {
        $result = $this->send('POST', '/products', [
            'sku' => '  NEW-2 ',
            'name' => ' Pliers ',
            'description' => null,
            'priceCents' => 0,
            'stock' => 0,
            'weightKg' => 2,
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame('NEW-2', $result['body']['data']['sku']);
        $this->assertSame('Pliers', $result['body']['data']['name']);
        $this->assertNull($result['body']['data']['description']);
        $this->assertNull($result['body']['data']['category']);
        $this->assertEquals(2.0, $result['body']['data']['weightKg']);
    }

    public function testCreateAcceptsBoundaryValues(): void
    {
        $body = [
            'sku' => str_repeat('s', 32),
            'name' => str_repeat('n', 255),
            'description' => str_repeat('d', 65535),
            'category' => str_repeat('c', 100),
            'priceCents' => 9_999_999_999,
            'stock' => 4_294_967_295,
            'weightKg' => 99999.999,
        ];

        $this->assertSame(201, $this->send('POST', '/products', $body)['status']);
    }

    public function testCreateDuplicateSkuIs409(): void
    {
        $body = $this->validBody();
        $body['sku'] = 'HAM-1';

        $result = $this->send('POST', '/products', $body);

        $this->assertSame(409, $result['status']);
        $this->assertSame(ActionError::CONFLICT, $result['body']['error']['type']);
    }

    public function testCreateMalformedJsonIs400(): void
    {
        $result = $this->sendRaw('POST', '/products', '{"sku": ');

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
    }

    public function testCreateWithoutBodyIs400(): void
    {
        $result = $this->send('POST', '/products');

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
    }

    public function testCreateScalarJsonBodyIs400(): void
    {
        $this->assertSame(400, $this->sendRaw('POST', '/products', '"hello"')['status']);
        $this->assertSame(400, $this->sendRaw('POST', '/products', '42')['status']);
    }

    /**
     * @dataProvider invalidBodyProvider
     * @param array<string, mixed> $overrides
     * @param list<string> $expectedFields
     */
    public function testCreateInvalidBodyIs400(array $overrides, array $expectedFields): void
    {
        $body = array_merge($this->validBody(), $overrides);
        foreach ($overrides as $key => $value) {
            if ($value === '__missing__') {
                unset($body[$key]);
            }
        }

        $result = $this->send('POST', '/products', $body);

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
        $fields = array_keys($result['body']['error']['fields']);
        sort($fields);
        sort($expectedFields);
        $this->assertSame($expectedFields, $fields);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function invalidBodyProvider(): array
    {
        return [
            'missing sku' => [['sku' => '__missing__'], ['sku']],
            'missing name' => [['name' => '__missing__'], ['name']],
            'missing priceCents' => [['priceCents' => '__missing__'], ['priceCents']],
            'missing stock' => [['stock' => '__missing__'], ['stock']],
            'null sku' => [['sku' => null], ['sku']],
            'null priceCents' => [['priceCents' => null], ['priceCents']],
            'sku not a string' => [['sku' => 123], ['sku']],
            'name not a string' => [['name' => ['x']], ['name']],
            'description not a string' => [['description' => 5], ['description']],
            'category not a string' => [['category' => true], ['category']],
            'priceCents string' => [['priceCents' => '12'], ['priceCents']],
            'priceCents float' => [['priceCents' => 12.5], ['priceCents']],
            'priceCents bool' => [['priceCents' => true], ['priceCents']],
            'stock string' => [['stock' => '3'], ['stock']],
            'stock float' => [['stock' => 3.5], ['stock']],
            'weightKg string' => [['weightKg' => '1.5'], ['weightKg']],
            'weightKg bool' => [['weightKg' => false], ['weightKg']],
            'empty sku' => [['sku' => ''], ['sku']],
            'blank sku' => [['sku' => '   '], ['sku']],
            'empty name' => [['name' => ''], ['name']],
            'blank name' => [['name' => "  \t "], ['name']],
            'sku too long' => [['sku' => str_repeat('s', 33)], ['sku']],
            'sku too long with trailing space' => [['sku' => str_repeat('s', 33) . ' '], ['sku']],
            'name too long' => [['name' => str_repeat('n', 256)], ['name']],
            'description too long' => [['description' => str_repeat('d', 65536)], ['description']],
            'category too long' => [['category' => str_repeat('c', 101)], ['category']],
            'negative price' => [['priceCents' => -1], ['priceCents']],
            'price too large' => [['priceCents' => 10_000_000_000], ['priceCents']],
            'negative stock' => [['stock' => -1], ['stock']],
            'stock too large' => [['stock' => 4_294_967_296], ['stock']],
            'negative weight' => [['weightKg' => -0.5], ['weightKg']],
            'weight too large' => [['weightKg' => 100000], ['weightKg']],
            'weight just over' => [['weightKg' => 99999.9991], ['weightKg']],
            'unknown key' => [['colour' => 'red'], ['colour']],
            'client-supplied id' => [['id' => 5], ['id']],
            'multiple errors' => [
                ['sku' => '', 'name' => 7, 'priceCents' => '1', 'stock' => -1, 'extra' => 1],
                ['sku', 'name', 'priceCents', 'stock', 'extra'],
            ],
        ];
    }

    public function testCreateEmptyObjectReportsAllRequiredFields(): void
    {
        $result = $this->sendRaw('POST', '/products', '{}');

        $this->assertSame(400, $result['status']);
        $fields = array_keys($result['body']['error']['fields']);
        sort($fields);
        $this->assertSame(['name', 'priceCents', 'sku', 'stock'], $fields);
    }

    public function testCreateViaRawJsonBodyWorks(): void
    {
        $result = $this->sendRaw('POST', '/products', (string) json_encode($this->validBody()));

        $this->assertSame(201, $result['status']);
    }

    public function testUpdateReplacesProduct(): void
    {
        $body = $this->updateBody();
        $body['description'] = null;
        $body['category'] = null;
        $body['weightKg'] = null;

        $result = $this->send('PUT', '/products/1', $body);

        $this->assertSame(200, $result['status']);
        $data = $result['body']['data'];
        $this->assertSame(1, $data['id']);
        $this->assertSame('NEW-1', $data['sku']);
        $this->assertSame('Wrench', $data['name']);
        $this->assertNull($data['description']);
        $this->assertNull($data['weightKg']);
        $this->assertSame(2500, $data['priceCents']);
        $this->assertSame('Wrench', $this->repo->findById(1)->name);
        $this->assertSame('2024-01-01T10:00:00+00:00', $data['createdAt']);
    }

    public function testUpdateBumpsVersionAndReturnsIt(): void
    {
        $result = $this->send('PUT', '/products/1', $this->updateBody(1));

        $this->assertSame(200, $result['status']);
        $this->assertSame(2, $result['body']['data']['version']);
        $this->assertSame(2, $this->repo->findById(1)->version);
    }

    public function testStaleUpdateIs409ConflictAndLeavesProductUntouched(): void
    {
        $this->assertSame(200, $this->send('PUT', '/products/1', $this->updateBody(1))['status']);
        $body = $this->updateBody(1); // second editor still holds version 1
        $body['name'] = 'Stale name';
        $body['stock'] = 50;

        $result = $this->send('PUT', '/products/1', $body);

        $this->assertSame(409, $result['status']);
        $this->assertSame(ActionError::CONFLICT, $result['body']['error']['type']);
        $this->assertSame(['version'], array_keys($result['body']['error']['fields']));
        $this->assertStringContainsString('modified by someone else', $result['body']['error']['description']);
        $this->assertSame('Wrench', $this->repo->findById(1)->name);
        $this->assertSame(10, $this->repo->findById(1)->stock);
    }

    public function testPurchaseBetweenReadAndPutInvalidatesTheStalePut(): void
    {
        $loadedVersion = $this->repo->findById(1)->version;
        $this->assertTrue($this->repo->decrementStock(1, 2));
        $body = $this->updateBody($loadedVersion);
        $body['stock'] = 5; // admin's absolute number, from before the purchase

        $result = $this->send('PUT', '/products/1', $body);

        $this->assertSame(409, $result['status']);
        $this->assertSame(3, $this->repo->findById(1)->stock);
    }

    public function testUpdateDuplicateSkuConflictHasNoVersionField(): void
    {
        $body = $this->updateBody();
        $body['sku'] = 'SAW-1';

        $result = $this->send('PUT', '/products/1', $body);

        $this->assertSame(409, $result['status']);
        $this->assertArrayNotHasKey('fields', $result['body']['error']);
    }

    public function testUpdateWithoutVersionIs400(): void
    {
        $result = $this->send('PUT', '/products/1', $this->validBody());

        $this->assertSame(400, $result['status']);
        $this->assertArrayHasKey('version', $result['body']['error']['fields']);
    }

    public function testUpdateUnknownIdIs404EvenWithStaleVersion(): void
    {
        $this->assertSame(404, $this->send('PUT', '/products/999', $this->updateBody(7))['status']);
    }

    public function testCreateRejectsVersion(): void
    {
        $result = $this->send('POST', '/products', $this->updateBody());

        $this->assertSame(400, $result['status']);
        $this->assertArrayHasKey('version', $result['body']['error']['fields']);
    }

    public function testUpdateKeepingOwnSkuIsAllowed(): void
    {
        $body = $this->updateBody();
        $body['sku'] = 'HAM-1';

        $this->assertSame(200, $this->send('PUT', '/products/1', $body)['status']);
    }

    public function testUpdateDuplicateSkuIs409(): void
    {
        $body = $this->updateBody();
        $body['sku'] = 'SAW-1';

        $result = $this->send('PUT', '/products/1', $body);

        $this->assertSame(409, $result['status']);
        $this->assertSame(ActionError::CONFLICT, $result['body']['error']['type']);
        $this->assertSame('HAM-1', $this->repo->findById(1)->sku);
    }

    public function testUpdateUnknownIdIs404(): void
    {
        $result = $this->send('PUT', '/products/999', $this->updateBody());

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
    }

    public function testUpdateInactiveProductIs404(): void
    {
        $result = $this->send('PUT', '/products/3', $this->updateBody());

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
        $this->assertSame('OLD-1', $this->repo->findById(3)->sku);
    }

    /**
     * @dataProvider invalidIdProvider
     */
    public function testUpdateAndDeleteInvalidIdIs400(string $path): void
    {
        $this->assertSame(400, $this->send('PUT', $path, $this->updateBody())['status']);
        $this->assertSame(400, $this->send('DELETE', $path)['status']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdProvider(): array
    {
        return ['non-numeric' => ['/products/abc'], 'zero' => ['/products/0']];
    }

    public function testUpdateMalformedJsonIs400(): void
    {
        $result = $this->sendRaw('PUT', '/products/1', '{nope');

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
    }

    public function testUpdateInvalidBodyIs400WithAllFields(): void
    {
        $result = $this->send('PUT', '/products/1', [
            'sku' => str_repeat('s', 40),
            'name' => '',
            'priceCents' => 1.5,
            'stock' => 1,
            'version' => 1,
            'bogus' => true,
        ]);

        $this->assertSame(400, $result['status']);
        $fields = array_keys($result['body']['error']['fields']);
        sort($fields);
        $this->assertSame(['bogus', 'name', 'priceCents', 'sku'], $fields);
        $this->assertSame('HAM-1', $this->repo->findById(1)->sku);
    }

    public function testUpdateMissingFieldIs400BecauseItIsAFullReplace(): void
    {
        $body = $this->updateBody();
        unset($body['stock']);

        $result = $this->send('PUT', '/products/1', $body);

        $this->assertSame(400, $result['status']);
        $this->assertArrayHasKey('stock', $result['body']['error']['fields']);
    }

    public function testDeactivateReturns204AndHidesProduct(): void
    {
        $result = $this->send('DELETE', '/products/1');

        $this->assertSame(204, $result['status']);
        $this->assertSame('', $result['raw']);
        $this->assertFalse($this->repo->findById(1)->active);
        $this->assertSame(404, $this->send('GET', '/products/1')['status']);
    }

    public function testDeactivateUnknownIdIs404(): void
    {
        $result = $this->send('DELETE', '/products/999');

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
    }

    public function testDeactivateAlreadyInactiveIs404(): void
    {
        $result = $this->send('DELETE', '/products/3');

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
    }

    public function testDeactivateTwiceSecondIs404(): void
    {
        $this->assertSame(204, $this->send('DELETE', '/products/2')['status']);
        $this->assertSame(404, $this->send('DELETE', '/products/2')['status']);
    }
}
