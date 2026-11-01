<?php

declare(strict_types=1);

namespace App\Infrastructure\Order;

use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Domain\Order\Exception\OrderNotFoundException;
use App\Domain\Order\OrderRepositoryInterface;
use App\Domain\Order\OrderStatus;
use DateTimeImmutable;
use PDO;
use Throwable;

final class MySqlOrderRepository implements OrderRepositoryInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function save(Order $order): Order
    {
        // Join an already active transaction (e.g. from TransactionManagerInterface).
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO orders (status, total_cents, created_at) VALUES (:status, :total, :created_at)'
            );
            $stmt->bindValue(':status', $order->status->value);
            $stmt->bindValue(':total', $order->totalCents, PDO::PARAM_INT);
            $stmt->bindValue(':created_at', $order->createdAt->format('Y-m-d H:i:s'));
            $stmt->execute();
            $orderId = (int) $this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, sku, quantity, unit_price_cents) '
                . 'VALUES (:order_id, :product_id, :product_name, :sku, :quantity, :unit_price)'
            );
            $items = [];
            foreach ($order->items as $item) {
                $itemStmt->bindValue(':order_id', $orderId, PDO::PARAM_INT);
                $itemStmt->bindValue(':product_id', $item->productId, PDO::PARAM_INT);
                $itemStmt->bindValue(':product_name', $item->productName);
                $itemStmt->bindValue(':sku', $item->sku);
                $itemStmt->bindValue(':quantity', $item->quantity, PDO::PARAM_INT);
                $itemStmt->bindValue(':unit_price', $item->unitPriceCents, PDO::PARAM_INT);
                $itemStmt->execute();

                $items[] = new OrderItem(
                    (int) $this->pdo->lastInsertId(),
                    $orderId,
                    $item->productId,
                    $item->productName,
                    $item->sku,
                    $item->quantity,
                    $item->unitPriceCents,
                );
            }

            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $e;
        }

        return new Order($orderId, $order->status, $order->totalCents, $order->createdAt, $items);
    }

    public function findById(int $id): Order
    {
        $stmt = $this->pdo->prepare('SELECT id, status, total_cents, created_at FROM orders WHERE id = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw OrderNotFoundException::forId($id);
        }

        $itemStmt = $this->pdo->prepare(
            'SELECT id, order_id, product_id, product_name, sku, quantity, unit_price_cents '
            . 'FROM order_items WHERE order_id = :order_id ORDER BY id'
        );
        $itemStmt->bindValue(':order_id', $id, PDO::PARAM_INT);
        $itemStmt->execute();

        $items = [];
        while ($r = $itemStmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = new OrderItem(
                (int) $r['id'],
                (int) $r['order_id'],
                (int) $r['product_id'],
                (string) $r['product_name'],
                (string) $r['sku'],
                (int) $r['quantity'],
                (int) $r['unit_price_cents'],
            );
        }

        return new Order(
            (int) $row['id'],
            OrderStatus::from((string) $row['status']),
            (int) $row['total_cents'],
            new DateTimeImmutable((string) $row['created_at']),
            $items,
        );
    }
}
