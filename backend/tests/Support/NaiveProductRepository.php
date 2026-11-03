<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Infrastructure\Product\MySqlProductRepository;

/**
 * Deliberately WRONG decrement (read, then write an absolute value, no
 * `stock >= ?` guard), with a pause in the gap. Used only to prove the
 * concurrency test detects overselling; never wire it into the app.
 */
final class NaiveProductRepository extends MySqlProductRepository
{
    public function __construct(private readonly \PDO $db)
    {
        parent::__construct($db);
    }

    /** Also WRONG on purpose: no row lock, so the harness check stays meaningful. */
    public function findByIdsForUpdate(array $ids): array
    {
        return $this->findByIds($ids);
    }

    public function decrementStock(int $productId, int $quantity): bool
    {
        $stmt = $this->db->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);
        $stock = (int) $stmt->fetchColumn();
        usleep(50_000);

        $upd = $this->db->prepare('UPDATE products SET stock = ? WHERE id = ?');
        $upd->execute([max(0, $stock - $quantity), $productId]);

        return true;
    }
}
