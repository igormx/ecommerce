<?php

declare(strict_types=1);

namespace Tests\Application\Services;

use App\Application\Services\Exception\InvalidPurchaseException;
use App\Application\Services\PurchaseService;
use App\Domain\Order\Order;
use App\Domain\Order\OrderRepositoryInterface;
use App\Domain\Order\OrderStatus;
use App\Domain\Payment\PaymentGatewayInterface;
use App\Domain\Persistence\TransactionManagerInterface;
use App\Domain\Product\Exception\InsufficientStockException;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    private ObjectProphecy $products;
    private ObjectProphecy $orders;
    private ObjectProphecy $payments;
    private ObjectProphecy $transactions;
    private PurchaseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->products = $this->prophesize(ProductRepositoryInterface::class);
        $this->orders = $this->prophesize(OrderRepositoryInterface::class);
        $this->payments = $this->prophesize(PaymentGatewayInterface::class);
        $this->transactions = $this->prophesize(TransactionManagerInterface::class);
        $this->transactions->run(Argument::type('callable'))->will(fn (array $args) => $args[0]());
        $this->orders->save(Argument::type(Order::class))->will(
            fn (array $args) => new Order(
                99,
                $args[0]->status,
                $args[0]->totalCents,
                $args[0]->createdAt,
                $args[0]->items,
            )
        );

        $this->service = new PurchaseService(
            $this->products->reveal(),
            $this->orders->reveal(),
            $this->payments->reveal(),
            $this->transactions->reveal(),
        );
    }

    private function product(int $id, int $price, int $stock, bool $active = true): Product
    {
        $now = new DateTimeImmutable();

        return new Product($id, 'SKU-' . $id, 'Name ' . $id, null, null, $price, $stock, null, $active, $now, $now);
    }

    public function testSuccessfulPurchaseSnapshotsItemsChargesAndDecrementsStock(): void
    {
        $this->products->findByIdsForUpdate([1, 2])->willReturn(
            [$this->product(1, 1000, 5), $this->product(2, 250, 10)]
        );
        $this->payments->charge(2500)->willReturn(true)->shouldBeCalledTimes(1);
        $this->products->decrementStock(1, 2)->willReturn(true)->shouldBeCalledTimes(1);
        $this->products->decrementStock(2, 2)->willReturn(true)->shouldBeCalledTimes(1);

        $order = $this->service->purchase([1 => 2, 2 => 2]);

        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame(2500, $order->totalCents);
        $this->assertCount(2, $order->items);
        $this->assertSame('SKU-1', $order->items[0]->sku);
        $this->assertSame('Name 1', $order->items[0]->productName);
        $this->assertSame(1000, $order->items[0]->unitPriceCents);
        $this->assertSame(2, $order->items[0]->quantity);
    }

    public function testDeclinedPaymentSavesFailedOrderAfterRollback(): void
    {
        $this->products->findByIdsForUpdate([1])->willReturn([$this->product(1, 666, 5)]);
        // Stock is decremented first, then the charge is declined; the real
        // TransactionManagerInterface rolls the decrement back when the work throws.
        $this->products->decrementStock(1, 1)->willReturn(true)->shouldBeCalledTimes(1);
        $this->payments->charge(666)->willReturn(false);

        $order = $this->service->purchase([1 => 1]);

        $this->assertSame(OrderStatus::FAILED, $order->status);
        $this->assertSame(666, $order->totalCents);
        $this->orders->save(Argument::that(fn (Order $o) => $o->status === OrderStatus::FAILED))
            ->shouldHaveBeenCalledTimes(1);
    }

    public function testMissingProductThrowsNotFound(): void
    {
        $this->products->findByIdsForUpdate([1, 2])->willReturn([$this->product(1, 100, 5)]);
        $this->payments->charge(Argument::any())->shouldNotBeCalled();

        $this->expectException(ProductNotFoundException::class);
        $this->service->purchase([1 => 1, 2 => 1]);
    }

    public function testInactiveProductThrowsNotFound(): void
    {
        $this->products->findByIdsForUpdate([1])->willReturn([$this->product(1, 100, 5, false)]);

        $this->expectException(ProductNotFoundException::class);
        $this->service->purchase([1 => 1]);
    }

    public function testInsufficientStockThrowsBeforeCharging(): void
    {
        $this->products->findByIdsForUpdate([1])->willReturn([$this->product(1, 100, 1)]);
        $this->payments->charge(Argument::any())->shouldNotBeCalled();

        $this->expectException(InsufficientStockException::class);
        $this->service->purchase([1 => 2]);
    }

    public function testNonPositiveQuantityThrowsValidationError(): void
    {
        $this->products->findByIdsForUpdate(Argument::any())->shouldNotBeCalled();

        $this->expectException(InvalidPurchaseException::class);
        $this->service->purchase([1 => 0]);
    }

    public function testNonIntegerQuantityOrIdThrowsValidationError(): void
    {
        $this->products->findByIdsForUpdate(Argument::any())->shouldNotBeCalled();

        try {
            $this->service->purchase([1 => '2']);
            $this->fail('Expected InvalidPurchaseException');
        } catch (InvalidPurchaseException) {
        }

        $this->expectException(InvalidPurchaseException::class);
        $this->service->purchase(['abc' => 1]);
    }

    public function testStockIsDecrementedInProductIdOrder(): void
    {
        $this->products->findByIdsForUpdate([1, 2])->willReturn([$this->product(1, 100, 5), $this->product(2, 100, 5)]);
        $this->payments->charge(200)->willReturn(true);
        $calls = [];
        $this->products->decrementStock(Argument::cetera())->will(function (array $args) use (&$calls) {
            $calls[] = $args[0];
            return true;
        });

        $this->service->purchase([2 => 1, 1 => 1]);

        $this->assertSame([1, 2], $calls);
    }

    public function testEmptyPurchaseThrowsValidationError(): void
    {
        $this->expectException(InvalidPurchaseException::class);
        $this->service->purchase([]);
    }

    public function testFailedAtomicDecrementThrowsAndOrderIsNotSaved(): void
    {
        $this->products->findByIdsForUpdate([1])->willReturn([$this->product(1, 100, 5)]);
        $this->payments->charge(Argument::any())->shouldNotBeCalled();
        $this->products->decrementStock(1, 1)->willReturn(false);
        // Re-read under the lock after losing the race: the real stock is reported.
        $this->products->findByIdsForUpdate([1])->willReturn([$this->product(1, 100, 0)]);

        try {
            $this->service->purchase([1 => 1]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame(1, $e->productId);
            $this->assertSame(1, $e->requested);
            $this->assertSame(0, $e->available);
        }

        $this->orders->save(Argument::any())->shouldNotHaveBeenCalled();
    }

    public function testInactiveProductIsTreatedAsNotFound(): void
    {
        $this->products->findByIdsForUpdate([1])->willReturn([$this->product(1, 1000, 5, false)]);
        $this->payments->charge(\Prophecy\Argument::any())->shouldNotBeCalled();
        $this->products->decrementStock(\Prophecy\Argument::cetera())->shouldNotBeCalled();

        $this->expectException(ProductNotFoundException::class);

        $this->service->purchase([1 => 1]);
    }

    public function testRejectsQuantityAboveMaximum(): void
    {
        $this->expectException(InvalidPurchaseException::class);

        $this->service->purchase([1 => PurchaseService::MAX_QUANTITY + 1]);
    }

    public function testRejectsTooManyLines(): void
    {
        $lines = array_fill_keys(range(1, PurchaseService::MAX_LINES + 1), 1);

        $this->expectException(InvalidPurchaseException::class);

        $this->service->purchase($lines);
    }

    public function testLostRaceReportsRealAvailableStockNotZero(): void
    {
        $this->products->findByIdsForUpdate([1])->willReturn(
            [$this->product(1, 100, 5)],
            [$this->product(1, 100, 3)]
        );
        $this->products->decrementStock(1, 4)->willReturn(false);

        try {
            $this->service->purchase([1 => 4]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame(4, $e->requested);
            $this->assertSame(3, $e->available);
        }
    }

    public function testInsufficientStockReportsLockedAvailableQuantity(): void
    {
        $this->products->findByIdsForUpdate([1])->willReturn([$this->product(1, 100, 3)]);

        try {
            $this->service->purchase([1 => 4]);
            $this->fail('Expected InsufficientStockException');
        } catch (InsufficientStockException $e) {
            $this->assertSame(4, $e->requested);
            $this->assertSame(3, $e->available);
        }
    }

    public function testLocksAreRequestedInAscendingIdOrder(): void
    {
        $this->products->findByIdsForUpdate([1, 2, 3])->willReturn([])->shouldBeCalledTimes(1);

        try {
            $this->service->purchase([3 => 1, 1 => 1, 2 => 1]);
        } catch (ProductNotFoundException) {
        }
    }

    public function testPriceChangedBeforeLockIsChargedAtLockedPrice(): void
    {
        $repo = new \Tests\Support\InMemoryProductRepository($this->product(1, 1000, 5));
        $stored = $repo->findById(1);
        // Another writer changes price and name just before the transaction locks the row.
        $changing = new class ($repo, $stored) implements TransactionManagerInterface {
            public function __construct(private \Tests\Support\InMemoryProductRepository $repo, private Product $p)
            {
            }

            public function run(callable $work): mixed
            {
                $this->repo->save(new Product(
                    $this->p->id,
                    $this->p->sku,
                    'Renamed',
                    null,
                    null,
                    2000,
                    $this->p->stock,
                    null,
                    true,
                    $this->p->createdAt,
                    $this->p->updatedAt,
                    $this->p->version,
                ));

                return $work();
            }
        };
        $charged = [];
        $this->payments->charge(Argument::any())->will(function (array $args) use (&$charged) {
            $charged[] = $args[0];
            return true;
        });
        $service = new PurchaseService($repo, $this->orders->reveal(), $this->payments->reveal(), $changing);

        $order = $service->purchase([1 => 2]);

        $this->assertSame([4000], $charged);
        $this->assertSame(4000, $order->totalCents);
        $this->assertSame(2000, $order->items[0]->unitPriceCents);
        $this->assertSame('Renamed', $order->items[0]->productName);
        $this->assertSame(3, $repo->findById(1)->stock);
    }

    public function testDeclinedPaymentUsesLockedTotalForFailedOrder(): void
    {
        $this->products->findByIdsForUpdate([1])->willReturn([$this->product(1, 333, 5)]);
        $this->products->decrementStock(1, 2)->willReturn(true);
        $this->payments->charge(666)->willReturn(false);

        $order = $this->service->purchase([1 => 2]);

        $this->assertSame(OrderStatus::FAILED, $order->status);
        $this->assertSame(666, $order->totalCents);
        $this->assertSame(333, $order->items[0]->unitPriceCents);
    }
}
