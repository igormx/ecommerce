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
}
