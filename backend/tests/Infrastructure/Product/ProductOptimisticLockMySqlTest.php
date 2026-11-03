<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Product;

use App\Application\Services\ProductService;
use App\Application\Services\PurchaseService;
use App\Domain\Product\Exception\StaleProductException;
use App\Infrastructure\Order\MySqlOrderRepository;
use App\Infrastructure\Payment\FakePaymentGateway;
use App\Infrastructure\Persistence\PdoTransactionManager;
use App\Infrastructure\Product\MySqlProductRepository;
use Tests\Support\MySqlIntegrationTestCase;

/**
 * The lost-update scenario against real MySQL: admin opens the form, a customer
 * buys, admin saves an absolute stock. Needs a test MySQL (`make test-concurrency`).
 */
class ProductOptimisticLockMySqlTest extends MySqlIntegrationTestCase
{
    private ProductService $products;
    private PurchaseService $purchases;

    protected function setUp(): void
    {
        parent::setUp();
        $repo = new MySqlProductRepository($this->pdo);
        $this->products = new ProductService($repo);
        $this->purchases = new PurchaseService(
            $repo,
            new MySqlOrderRepository($this->pdo),
            new FakePaymentGateway(),
            new PdoTransactionManager($this->pdo)
        );
    }

    public function testStalePutAfterPurchaseIsRejectedAndStockIsNotReverted(): void
    {
        $id = $this->insertProduct('LOST-1', 50);
        $loaded = $this->products->get($id); // admin opens the edit form at stock 50

        $this->purchases->purchase([$id => 10]); // customers buy 10

        try {
            $this->products->update($id, 'LOST-1', 'Renamed', null, null, 1000, 50, $loaded->version);
            $this->fail('Expected StaleProductException');
        } catch (StaleProductException) {
            // expected
        }
        $fresh = $this->products->get($id);
        $this->assertSame(40, $fresh->stock);
        $this->assertSame('Product LOST-1', $fresh->name);
        $this->assertGreaterThan($loaded->version, $fresh->version);
    }

    public function testFreshPutSucceedsAndBumpsVersion(): void
    {
        $id = $this->insertProduct('FRESH-1', 50);
        $this->purchases->purchase([$id => 10]);
        $loaded = $this->products->get($id);

        $updated = $this->products->update($id, 'FRESH-1', 'Renamed', null, null, 1000, 40, $loaded->version);

        $this->assertSame('Renamed', $updated->name);
        $this->assertSame($loaded->version + 1, $updated->version);
    }

    public function testSecondEditorWithSameVersionLoses(): void
    {
        $id = $this->insertProduct('RACE-1', 5);
        $v = $this->products->get($id)->version;

        $this->products->update($id, 'RACE-1', 'First', null, null, 1000, 5, $v);
        $this->expectException(StaleProductException::class);
        $this->products->update($id, 'RACE-1', 'Second', null, null, 1000, 5, $v);
    }

    public function testUnchangedSaveStillBumpsVersion(): void
    {
        // An identical re-save changes no data column; the version bump must still count as a write.
        $id = $this->insertProduct('SAME-1', 5);
        $p = $this->products->get($id);

        $updated = $this->products->update($id, 'SAME-1', 'Product SAME-1', null, null, 1000, 5, $p->version);

        $this->assertSame($p->version + 1, $updated->version);
    }
}
