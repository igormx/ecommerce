<?php

declare(strict_types=1);

namespace Tests\Application\Services;

use App\Application\Services\Exception\ValidationException;
use App\Application\Services\ProductService;
use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Tests\Support\InMemoryProductRepository;

class ProductServiceTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<ProductRepositoryInterface> */
    private ObjectProphecy $repo;
    private ProductService $service;

    protected function setUp(): void
    {
        $this->repo = $this->prophesize(ProductRepositoryInterface::class);
        $this->service = new ProductService($this->repo->reveal());
    }

    private function product(?int $id = 1, string $sku = 'SKU-1', bool $active = true): Product
    {
        $now = new DateTimeImmutable('2024-01-01 00:00:00');

        return new Product($id, $sku, 'Name', null, 'Cat', 1000, 5, 1.5, $active, $now, $now);
    }

    public function testCreateSavesTrimmedActiveProduct(): void
    {
        $this->repo->findBySku('SKU-1')->willThrow(ProductNotFoundException::forSku('SKU-1'));
        $this->repo->save(Argument::that(
            fn (Product $p): bool => $p->id === null
                && $p->sku === 'SKU-1'
                && $p->name === 'Widget'
                && $p->description === null
                && $p->category === null
                && $p->priceCents === 1999
                && $p->active === true
        ))->willReturn($this->product())->shouldBeCalledOnce();

        $result = $this->service->create(' SKU-1 ', ' Widget ', '  ', '', 1999, 3, null);

        $this->assertSame(1, $result->id);
    }

    public function testCreateRejectsDuplicateSku(): void
    {
        $this->repo->findBySku('SKU-1')->willReturn($this->product());
        $this->repo->save(Argument::any())->shouldNotBeCalled();

        $this->expectException(DuplicateSkuException::class);
        $this->service->create('SKU-1', 'Widget', null, null, 100, 1);
    }

    public function testCreateCollectsAllValidationErrors(): void
    {
        $this->repo->save(Argument::any())->shouldNotBeCalled();

        try {
            $this->service->create(' ', ' ', null, null, -1, -2, -0.5);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['name', 'sku', 'priceCents', 'stock', 'weightKg'],
                array_keys($e->errors)
            );
        }
    }

    public function testCreateRejectsValuesTooLargeForTheDatabase(): void
    {
        $this->repo->save(Argument::any())->shouldNotBeCalled();

        try {
            $this->service->create(
                str_repeat('s', 33),
                str_repeat('n', 256),
                str_repeat('d', 65536),
                str_repeat('c', 101),
                ProductService::MAX_PRICE_CENTS + 1,
                ProductService::MAX_STOCK + 1,
                100000.0,
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(
                ['name', 'sku', 'description', 'category', 'priceCents', 'stock', 'weightKg'],
                array_keys($e->errors)
            );
        }
    }

    public function testUpdateRejectsValuesTooLargeForTheDatabase(): void
    {
        $this->repo->findById(7)->willReturn($this->product(7, 'SKU-7'));
        $this->repo->save(Argument::any())->shouldNotBeCalled();

        $this->expectException(ValidationException::class);
        $this->service->update(7, str_repeat('s', 33), 'N', null, null, 1, 1);
    }

    public function testLimitsAreInclusive(): void
    {
        $sku = str_repeat('s', 32);
        $this->repo->findBySku($sku)->willThrow(ProductNotFoundException::forSku($sku));
        $this->repo->save(Argument::type(Product::class))->willReturn($this->product())->shouldBeCalledOnce();

        $this->service->create(
            $sku,
            str_repeat('n', 255),
            str_repeat('d', 65535),
            str_repeat('c', 100),
            ProductService::MAX_PRICE_CENTS,
            ProductService::MAX_STOCK,
            ProductService::MAX_WEIGHT_KG,
        );
    }

    public function testLengthsAreMeasuredAfterTrimmingAndInCharacters(): void
    {
        $sku = str_repeat('é', 32);
        $this->repo->findBySku($sku)->willThrow(ProductNotFoundException::forSku($sku));
        $this->repo->save(Argument::type(Product::class))->willReturn($this->product())->shouldBeCalledOnce();

        // 32 two-byte characters plus padding that trimming removes.
        $this->service->create(' ' . $sku . ' ', 'N', null, '  ', 1, 1);
    }

    public function testDescriptionLimitIsInBytes(): void
    {
        $this->repo->save(Argument::any())->shouldNotBeCalled();

        try {
            // 40000 two-byte characters = 80000 bytes, over TEXT's 65535.
            $this->service->create('S', 'N', str_repeat('é', 40000), null, 1, 1);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['description'], array_keys($e->errors));
        }
    }

    public function testWeightInfinityIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->create('S', 'N', null, null, 1, 1, INF);
    }

    public function testZeroValuesAreValid(): void
    {
        $this->repo->findBySku('S')->willThrow(ProductNotFoundException::forSku('S'));
        $this->repo->save(Argument::type(Product::class))->willReturn($this->product());

        $this->service->create('S', 'N', null, null, 0, 0, 0.0);
        $this->addToAssertionCount(1);
    }

    public function testUpdateKeepsSameSkuWithoutUniquenessCheck(): void
    {
        $existing = $this->product(7, 'SKU-7');
        $this->repo->findById(7)->willReturn($existing);
        $this->repo->findBySku(Argument::any())->shouldNotBeCalled();
        $this->repo->save(Argument::that(
            fn (Product $p): bool => $p->id === 7
                && $p->name === 'New'
                && $p->createdAt === $existing->createdAt
        ))->willReturn($existing)->shouldBeCalledOnce();

        $this->service->update(7, 'SKU-7', 'New', null, null, 500, 2);
    }

    public function testUpdateRejectsSkuOwnedByAnotherProduct(): void
    {
        $this->repo->findById(7)->willReturn($this->product(7, 'SKU-7'));
        $this->repo->findBySku('TAKEN')->willReturn($this->product(8, 'TAKEN'));

        $this->expectException(DuplicateSkuException::class);
        $this->service->update(7, 'TAKEN', 'New', null, null, 500, 2);
    }

    public function testUpdateMissingProductThrowsNotFound(): void
    {
        $this->repo->findById(99)->willThrow(ProductNotFoundException::forId(99));

        $this->expectException(ProductNotFoundException::class);
        $this->service->update(99, 'X', 'N', null, null, 1, 1);
    }

    public function testDeactivateDelegates(): void
    {
        $this->repo->deactivate(3)->shouldBeCalledOnce();
        $this->service->deactivate(3);
        $this->addToAssertionCount(1);
    }

    public function testListClampsPagination(): void
    {
        $this->repo->findAll(1, 100)->willReturn([])->shouldBeCalledOnce();
        $this->assertSame([], $this->service->list(0, 5000));
    }

    public function testListClampsHugePage(): void
    {
        $this->repo->findAll(ProductService::MAX_PAGE, 20)->willReturn([])->shouldBeCalledOnce();
        $this->assertSame([], $this->service->list(PHP_INT_MAX));
    }

    public function testSearchClampsHugePage(): void
    {
        $this->repo->search('shoe', null, ProductService::MAX_PAGE, 20)->willReturn([])->shouldBeCalledOnce();
        $this->service->search('shoe', null, PHP_INT_MAX);
        $this->addToAssertionCount(1);
    }

    public function testSearchTrimsAndNormalizesBlankCategory(): void
    {
        $this->repo->search('shoe', null, 2, 10)->willReturn([])->shouldBeCalledOnce();
        $this->service->search('  shoe ', '  ', 2, 10);
        $this->addToAssertionCount(1);
    }

    public function testGetDelegates(): void
    {
        $p = $this->product();
        $this->repo->findById(1)->willReturn($p);
        $this->assertSame($p, $this->service->get(1));
    }

    public function testCountDelegatesToRepository(): void
    {
        $this->repo->countAll()->willReturn(7)->shouldBeCalledOnce();

        $this->assertSame(7, $this->service->count());
    }

    public function testCountSearchTrimsQueryAndNormalizesBlankCategory(): void
    {
        $this->repo->countSearch('shoe', null)->willReturn(4)->shouldBeCalledOnce();

        $this->assertSame(4, $this->service->countSearch('  shoe ', '  '));
    }

    public function testCountSearchDefaultsCategoryToNull(): void
    {
        $this->repo->countSearch('shoe', null)->willReturn(1)->shouldBeCalledOnce();

        $this->assertSame(1, $this->service->countSearch('shoe'));
    }

    public function testCountAgreesWithListUsingInMemoryRepository(): void
    {
        $now = new DateTimeImmutable('2024-01-01');
        $repo = new InMemoryProductRepository(
            new Product(null, 'A', 'Alpha', null, 'Cat', 100, 1, null, true, $now, $now),
            new Product(null, 'B', 'Beta', null, 'Cat', 100, 1, null, true, $now, $now),
            new Product(null, 'C', 'Gamma', null, 'Cat', 100, 1, null, false, $now, $now),
        );
        $service = new ProductService($repo);

        $this->assertSame(2, $service->count());
        $this->assertCount(2, $service->list(1, 20));
    }

    public function testCountSearchAgreesWithSearchUsingInMemoryRepository(): void
    {
        $now = new DateTimeImmutable('2024-01-01');
        $repo = new InMemoryProductRepository(
            new Product(null, 'A', 'Alpha Widget', null, 'Cat', 100, 1, null, true, $now, $now),
            new Product(null, 'B', 'Beta Widget', null, 'Other', 100, 1, null, true, $now, $now),
            new Product(null, 'C', 'Gamma', null, 'Cat', 100, 1, null, true, $now, $now),
        );
        $service = new ProductService($repo);

        $this->assertSame(2, $service->countSearch('widget'));
        $this->assertCount(2, $service->search('widget'));

        $this->assertSame(1, $service->countSearch('  widget  ', ' Cat '));
        $this->assertCount(1, $service->search('  widget  ', ' Cat '));
    }

    public function testListCategoriesDelegatesToRepository(): void
    {
        $this->repo->findCategories()->willReturn(['A', 'B'])->shouldBeCalledOnce();

        $this->assertSame(['A', 'B'], $this->service->listCategories());
    }

    public function testListCategoriesAreDistinctSortedAndExcludeNullAndInactiveUsingInMemoryRepository(): void
    {
        $now = new DateTimeImmutable('2024-01-01');
        $repo = new InMemoryProductRepository(
            new Product(null, 'A', 'A', null, 'Zebra', 100, 1, null, true, $now, $now),
            new Product(null, 'B', 'B', null, 'Audio', 100, 1, null, true, $now, $now),
            new Product(null, 'C', 'C', null, 'Audio', 100, 1, null, true, $now, $now),
            new Product(null, 'D', 'D', null, null, 100, 1, null, true, $now, $now),
            new Product(null, 'E', 'E', null, 'Hidden', 100, 1, null, false, $now, $now),
        );
        $service = new ProductService($repo);

        $this->assertSame(['Audio', 'Zebra'], $service->listCategories());
    }
}
