<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Order;

use App\Domain\Order\Exception\OrderNotFoundException;
use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Order\OrderStatus;
use App\Infrastructure\Order\MySqlOrderRepository;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;

class MySqlOrderRepositoryTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PDO> */
    private ObjectProphecy $pdo;
    private MySqlOrderRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = $this->prophesize(PDO::class);
        $this->repo = new MySqlOrderRepository($this->pdo->reveal());
    }

    /**
     * @param array<int, array{0: mixed, 1: mixed, 2: mixed}> $binds recorded bindValue calls
     * @return ObjectProphecy<PDOStatement>
     */
    private function recordingStmt(array &$binds): ObjectProphecy
    {
        $stmt = $this->prophesize(PDOStatement::class);
        $stmt->bindValue(Argument::cetera())->will(function (array $args) use (&$binds) {
            $binds[] = [$args[0], $args[1], $args[2] ?? PDO::PARAM_STR];

            return true;
        });
        $stmt->execute()->willReturn(true);

        return $stmt;
    }

    private function order(): Order
    {
        return new Order(null, OrderStatus::PAID, 3000, new DateTimeImmutable('2024-03-04 05:06:07'), [
            new OrderItem(null, null, 10, 'Widget', 'W-1', 2, 1000),
            new OrderItem(null, null, 11, 'Gadget', 'G-1', 1, 1000),
        ]);
    }

    public function testSaveInsertsOrderAndItemsInOwnTransaction(): void
    {
        $orderBinds = [];
        $itemBinds = [];
        $orderStmt = $this->recordingStmt($orderBinds);
        $itemStmt = $this->recordingStmt($itemBinds);

        $this->pdo->inTransaction()->willReturn(false);
        $this->pdo->beginTransaction()->willReturn(true)->shouldBeCalledOnce();
        $this->pdo->prepare(Argument::containingString('INSERT INTO orders'))
            ->willReturn($orderStmt->reveal())->shouldBeCalledOnce();
        $this->pdo->prepare(Argument::containingString('INSERT INTO order_items'))
            ->willReturn($itemStmt->reveal())->shouldBeCalledOnce();
        $this->pdo->lastInsertId()->willReturn('7', '100', '101');
        $this->pdo->commit()->willReturn(true)->shouldBeCalledOnce();
        $this->pdo->rollBack()->shouldNotBeCalled();

        $saved = $this->repo->save($this->order());

        $this->assertSame([
            [':status', 'PAID', PDO::PARAM_STR],
            [':total', 3000, PDO::PARAM_INT],
            [':created_at', '2024-03-04 05:06:07', PDO::PARAM_STR],
        ], $orderBinds);
        $this->assertSame([
            [':order_id', 7, PDO::PARAM_INT],
            [':product_id', 10, PDO::PARAM_INT],
            [':product_name', 'Widget', PDO::PARAM_STR],
            [':sku', 'W-1', PDO::PARAM_STR],
            [':quantity', 2, PDO::PARAM_INT],
            [':unit_price', 1000, PDO::PARAM_INT],
            [':order_id', 7, PDO::PARAM_INT],
            [':product_id', 11, PDO::PARAM_INT],
            [':product_name', 'Gadget', PDO::PARAM_STR],
            [':sku', 'G-1', PDO::PARAM_STR],
            [':quantity', 1, PDO::PARAM_INT],
            [':unit_price', 1000, PDO::PARAM_INT],
        ], $itemBinds);
        $itemStmt->execute()->shouldHaveBeenCalledTimes(2);

        $this->assertSame(7, $saved->id);
        $this->assertSame(OrderStatus::PAID, $saved->status);
        $this->assertSame(3000, $saved->totalCents);
        $this->assertCount(2, $saved->items);
        $this->assertSame(100, $saved->items[0]->id);
        $this->assertSame(7, $saved->items[0]->orderId);
        $this->assertSame('Widget', $saved->items[0]->productName);
        $this->assertSame(101, $saved->items[1]->id);
        $this->assertSame(11, $saved->items[1]->productId);
    }

    public function testSaveJoinsActiveTransaction(): void
    {
        $binds = [];
        $stmt = $this->recordingStmt($binds);
        $this->pdo->inTransaction()->willReturn(true);
        $this->pdo->beginTransaction()->shouldNotBeCalled();
        $this->pdo->commit()->shouldNotBeCalled();
        $this->pdo->rollBack()->shouldNotBeCalled();
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());
        $this->pdo->lastInsertId()->willReturn('5');

        $saved = $this->repo->save(new Order(null, OrderStatus::FAILED, 0, new DateTimeImmutable('2024-01-01')));

        $this->assertSame(5, $saved->id);
        $this->assertSame([], $saved->items);
        $this->assertSame('FAILED', $binds[0][1]);
    }

    public function testSaveRollsBackOwnTransactionOnFailure(): void
    {
        $error = new RuntimeException('insert failed');
        $stmt = $this->prophesize(PDOStatement::class);
        $stmt->bindValue(Argument::cetera())->willReturn(true);
        $stmt->execute()->willThrow($error);

        $this->pdo->inTransaction()->willReturn(false, true);
        $this->pdo->beginTransaction()->willReturn(true)->shouldBeCalledOnce();
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());
        $this->pdo->commit()->shouldNotBeCalled();
        $this->pdo->rollBack()->willReturn(true)->shouldBeCalledOnce();

        try {
            $this->repo->save($this->order());
            $this->fail('Expected exception');
        } catch (RuntimeException $e) {
            $this->assertSame($error, $e);
        }
    }

    public function testSaveDoesNotRollBackJoinedTransactionOnFailure(): void
    {
        $stmt = $this->prophesize(PDOStatement::class);
        $stmt->bindValue(Argument::cetera())->willReturn(true);
        $stmt->execute()->willThrow(new RuntimeException('fail'));

        $this->pdo->inTransaction()->willReturn(true);
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());
        $this->pdo->rollBack()->shouldNotBeCalled();
        $this->pdo->commit()->shouldNotBeCalled();

        $this->expectException(RuntimeException::class);
        $this->repo->save($this->order());
    }

    public function testFindByIdHydratesOrderWithItems(): void
    {
        $orderBinds = [];
        $itemBinds = [];
        $orderStmt = $this->recordingStmt($orderBinds);
        $orderStmt->fetch(PDO::FETCH_ASSOC)->willReturn([
            'id' => '7', 'status' => 'PAID', 'total_cents' => '3000', 'created_at' => '2024-03-04 05:06:07',
        ]);
        $itemStmt = $this->recordingStmt($itemBinds);
        $itemStmt->fetch(PDO::FETCH_ASSOC)->willReturn(
            ['id' => '100', 'order_id' => '7', 'product_id' => '10', 'product_name' => 'Widget',
                'sku' => 'W-1', 'quantity' => '2', 'unit_price_cents' => '1000'],
            ['id' => '101', 'order_id' => '7', 'product_id' => '11', 'product_name' => 'Gadget',
                'sku' => 'G-1', 'quantity' => '1', 'unit_price_cents' => '1000'],
            false
        );
        $this->pdo->prepare(Argument::containingString('FROM orders WHERE id'))->willReturn($orderStmt->reveal());
        $this->pdo->prepare(Argument::containingString('FROM order_items WHERE order_id'))
            ->willReturn($itemStmt->reveal());

        $order = $this->repo->findById(7);

        $this->assertSame([[':id', 7, PDO::PARAM_INT]], $orderBinds);
        $this->assertSame([[':order_id', 7, PDO::PARAM_INT]], $itemBinds);
        $this->assertSame(7, $order->id);
        $this->assertSame(OrderStatus::PAID, $order->status);
        $this->assertSame(3000, $order->totalCents);
        $this->assertSame('2024-03-04 05:06:07', $order->createdAt->format('Y-m-d H:i:s'));
        $this->assertCount(2, $order->items);
        $this->assertSame(100, $order->items[0]->id);
        $this->assertSame(7, $order->items[0]->orderId);
        $this->assertSame(10, $order->items[0]->productId);
        $this->assertSame('W-1', $order->items[0]->sku);
        $this->assertSame(2, $order->items[0]->quantity);
        $this->assertSame(1000, $order->items[0]->unitPriceCents);
        $this->assertSame('Gadget', $order->items[1]->productName);
    }

    public function testFindByIdThrowsWhenMissing(): void
    {
        $binds = [];
        $stmt = $this->recordingStmt($binds);
        $stmt->fetch(PDO::FETCH_ASSOC)->willReturn(false);
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal())->shouldBeCalledOnce();

        $this->expectException(OrderNotFoundException::class);
        $this->expectExceptionMessage('Order with id 9 not found.');
        $this->repo->findById(9);
    }
}
