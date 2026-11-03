<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests that need a real MySQL. They run only when TEST_DB_HOST is set
 * (see `make test-concurrency`) and are skipped otherwise. The schema is dropped
 * and recreated, so the database name must end in `_test`; the live `ecommerce`
 * database can never be targeted.
 */
abstract class MySqlIntegrationTestCase extends TestCase
{
    protected PDO $pdo;

    /** @return array{host: string, port: int, name: string, user: string, password: string} */
    public static function dbConfig(): array
    {
        return [
            'host' => (string) getenv('TEST_DB_HOST'),
            'port' => (int) (getenv('TEST_DB_PORT') ?: 3306),
            'name' => (string) (getenv('TEST_DB_NAME') ?: 'ecommerce_test'),
            'user' => (string) (getenv('TEST_DB_USER') ?: 'root'),
            'password' => (string) (getenv('TEST_DB_PASSWORD') ?: 'root'),
        ];
    }

    public static function connect(): PDO
    {
        $c = self::dbConfig();

        return new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']),
            $c['user'],
            $c['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    protected function setUp(): void
    {
        if (getenv('TEST_DB_HOST') === false || getenv('TEST_DB_HOST') === '') {
            $this->markTestSkipped(
                'No test MySQL configured. Set TEST_DB_HOST (and optionally TEST_DB_PORT/NAME/USER/PASSWORD), '
                . 'or run `make test-concurrency`.'
            );
        }
        $config = self::dbConfig();
        if (!str_ends_with($config['name'], '_test')) {
            $this->fail('Refusing to reset database "' . $config['name'] . '": its name must end in _test.');
        }

        $this->pdo = self::connect();
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['order_items', 'orders', 'products'] as $table) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $table);
        }
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $schema = (string) file_get_contents(__DIR__ . '/../../db/init/01-schema.sql');
        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            $this->pdo->exec($statement);
        }
    }

    protected function insertProduct(string $sku, int $stock, string $price = '10.00'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO products (sku, name, price, stock) VALUES (?, ?, ?, ?)');
        $stmt->execute([$sku, 'Product ' . $sku, $price, $stock]);

        return (int) $this->pdo->lastInsertId();
    }

    protected function stockOf(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT stock FROM products WHERE id = ?');
        $stmt->execute([$productId]);

        return (int) $stmt->fetchColumn();
    }
}
