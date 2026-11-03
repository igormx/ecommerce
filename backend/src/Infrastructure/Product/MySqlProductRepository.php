<?php

declare(strict_types=1);

namespace App\Infrastructure\Product;

use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\StaleProductException;
use App\Domain\Product\Money;
use App\Domain\Product\Product;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;

class MySqlProductRepository implements ProductRepositoryInterface
{
    private const COLUMNS = 'id, sku, name, description, category, CAST(price AS CHAR) AS price, stock, '
        . 'CAST(weight_kg AS CHAR) AS weight_kg, active, created_at, updated_at, version';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findAll(int $page, int $size): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM products WHERE active = 1 ORDER BY id LIMIT ? OFFSET ?'
        );
        $stmt->bindValue(1, $size, PDO::PARAM_INT);
        $stmt->bindValue(2, $this->offset($page, $size), PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE active = 1');
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    public function findCategories(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT category FROM products WHERE active = 1 AND category IS NOT NULL ORDER BY category'
        );
        $stmt->execute();

        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function findById(int $id): Product
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM products WHERE id = ?');
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw ProductNotFoundException::forId($id);
        }

        return $this->hydrate($row);
    }

    public function findBySku(string $sku): Product
    {
        $stmt = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM products WHERE sku = ?');
        $stmt->bindValue(1, $sku);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw ProductNotFoundException::forSku($sku);
        }

        return $this->hydrate($row);
    }

    public function save(Product $product): Product
    {
        try {
            if ($product->id === null) {
                $id = $this->insert($product);
            } else {
                $this->update($product);
                $id = $product->id;
            }
        } catch (PDOException $e) {
            if ($this->isDuplicateSku($e)) {
                throw new DuplicateSkuException($product->sku);
            }
            throw $e;
        }

        // Throws ProductNotFoundException if an update targeted a missing row.
        return $this->findById($id);
    }

    public function deactivate(int $id): void
    {
        // Existence check first: rowCount() is 0 when the product is already inactive.
        $this->findById($id);

        $stmt = $this->pdo->prepare('UPDATE products SET active = 0, version = version + 1 WHERE id = ?');
        $stmt->bindValue(1, $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function search(string $query, ?string $category, int $page, int $size): array
    {
        $query = trim($query);
        [$where, $params] = $this->searchWhere($query, $category);
        $sql = 'SELECT ' . self::COLUMNS . ' FROM products ' . $where;

        if ($query !== '') {
            $sql .= ' ORDER BY MATCH(name, description, category) AGAINST (? IN NATURAL LANGUAGE MODE) DESC, id';
            $params[] = $query;
        } else {
            $sql .= ' ORDER BY id';
        }
        $sql .= ' LIMIT ? OFFSET ?';

        $stmt = $this->pdo->prepare($sql);
        $i = 1;
        foreach ($params as $param) {
            $stmt->bindValue($i++, $param);
        }
        $stmt->bindValue($i++, $size, PDO::PARAM_INT);
        $stmt->bindValue($i, $this->offset($page, $size), PDO::PARAM_INT);
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function countSearch(string $query, ?string $category): int
    {
        [$where, $params] = $this->searchWhere(trim($query), $category);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM products ' . $where);
        $i = 1;
        foreach ($params as $param) {
            $stmt->bindValue($i++, $param);
        }
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * The `WHERE` clause shared by search() and countSearch(), so the two
     * cannot drift. $query must already be trimmed.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    private function searchWhere(string $query, ?string $category): array
    {
        $sql = 'WHERE active = 1';
        $params = [];

        if ($query !== '') {
            $sql .= ' AND MATCH(name, description, category) AGAINST (? IN NATURAL LANGUAGE MODE)';
            $params[] = $query;
        }
        if ($category !== null) {
            $sql .= ' AND category = ?';
            $params[] = $category;
        }

        return [$sql, $params];
    }

    public function findByIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM products WHERE id IN (' . $placeholders . ') ORDER BY id'
        );
        foreach ($ids as $i => $id) {
            $stmt->bindValue($i + 1, $id, PDO::PARAM_INT);
        }
        $stmt->execute();

        return $this->hydrateAll($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function decrementStock(int $productId, int $quantity): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET stock = stock - ?, version = version + 1 WHERE id = ? AND stock >= ? AND active = 1'
        );
        $stmt->bindValue(1, $quantity, PDO::PARAM_INT);
        $stmt->bindValue(2, $productId, PDO::PARAM_INT);
        $stmt->bindValue(3, $quantity, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    private function insert(Product $product): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO products (sku, name, description, category, price, stock, weight_kg, active) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $this->bindWritable($stmt, $product);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function update(Product $product): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE products SET sku = ?, name = ?, description = ?, category = ?, price = ?, '
            . 'stock = ?, weight_kg = ?, active = ?, version = version + 1 WHERE id = ? AND version = ?'
        );
        $this->bindWritable($stmt, $product);
        $stmt->bindValue(9, $product->id, PDO::PARAM_INT);
        $stmt->bindValue(10, $product->version, PDO::PARAM_INT);
        $stmt->execute();

        // The row exists (callers check), so no match means the version moved on.
        if ($stmt->rowCount() === 0) {
            throw new StaleProductException((int) $product->id);
        }
    }

    private function bindWritable(PDOStatement $stmt, Product $product): void
    {
        $stmt->bindValue(1, $product->sku);
        $stmt->bindValue(2, $product->name);
        $stmt->bindValue(3, $product->description, $product->description === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(4, $product->category, $product->category === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(5, Money::centsToDecimal($product->priceCents));
        $stmt->bindValue(6, $product->stock, PDO::PARAM_INT);
        $stmt->bindValue(
            7,
            $product->weightKg === null ? null : number_format($product->weightKg, 3, '.', ''),
            $product->weightKg === null ? PDO::PARAM_NULL : PDO::PARAM_STR
        );
        $stmt->bindValue(8, $product->active ? 1 : 0, PDO::PARAM_INT);
    }

    private function isDuplicateSku(PDOException $e): bool
    {
        $driverCode = $e->errorInfo[1] ?? null;
        $isDuplicate = $e->getCode() === '23000' && ($driverCode === null || $driverCode === 1062);

        return $isDuplicate && str_contains($e->getMessage(), 'uq_products_sku');
    }

    private function offset(int $page, int $size): int
    {
        return (max(1, $page) - 1) * $size;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return Product[]
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): Product => $this->hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Product
    {
        return new Product(
            (int) $row['id'],
            (string) $row['sku'],
            (string) $row['name'],
            $row['description'] === null ? null : (string) $row['description'],
            $row['category'] === null ? null : (string) $row['category'],
            Money::decimalToCents((string) $row['price']),
            (int) $row['stock'],
            $row['weight_kg'] === null ? null : (float) $row['weight_kg'],
            (bool) $row['active'],
            new DateTimeImmutable((string) $row['created_at']),
            new DateTimeImmutable((string) $row['updated_at']),
            (int) $row['version'],
        );
    }
}
