<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Exception\OrderNotFoundException;

interface OrderRepositoryInterface
{
    /**
     * Persists the order together with its items in a single operation
     * (implementations should do this inside a DB transaction). Returns the
     * persisted order (with its id, and its items' ids/orderId, populated).
     */
    public function save(Order $order): Order;

    /**
     * @throws OrderNotFoundException
     */
    public function findById(int $id): Order;
}
