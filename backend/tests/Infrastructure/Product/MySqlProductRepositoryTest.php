<?php

declare(strict_types=1);

namespace Tests\Infrastructure\Product;

use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\ProductNotFoundException;
use App\Domain\Product\Product;
use App\Infrastructure\Product\MySqlProductRepository;
use DateTimeImmutable;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

class MySqlProductRepositoryTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<PDO> */
    private ObjectProphecy $pdo;
    private MySqlProductRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = $this->prophesize(PDO::class);
        $this->repo = new MySqlProductRepository($this->pdo->reveal());
    }

    /**
     * @param array<int, array{0: mixed, 1: mixed, 2: mixed}> $binds
     * @return ObjectProphecy<PDOStatement>
     */
    private function stmt(array &$binds): ObjectProphecy
    {
        $stmt = $this->prophesize(PDOStatement::class);
        $stmt->bindValue(Argument::cetera())->will(function (array $args) use (&$binds) {
            $binds[] = [$args[0], $args[1], $args[2] ?? PDO::PARAM_STR];

            return true;
        });
        $stmt->execute()->willReturn(true);

        return $stmt;
    }

    /** @return array<string, mixed> */
    private function row(array $overrides = []): array
    {
        return array_merge([
            'id' => '1', 'sku' => 'SKU-1', 'name' => 'Widget', 'description' => 'Desc', 'category' => 'Cat',
            'price' => '19.99', 'stock' => '5', 'weight_kg' => '1.500', 'active' => '1',
            'created_at' => '2024-01-01 00:00:00', 'updated_at' => '2024-01-02 00:00:00',
        ], $overrides);
    }

    private function product(?int $id = null): Product
    {
        $now = new DateTimeImmutable('2024-01-01 00:00:00');

        return new Product($id, 'SKU-1', 'Widget', 'Desc', 'Cat', 1999, 5, 1.5, true, $now, $now);
    }

    public function testFindAllBindsLimitAndOffset(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetchAll(PDO::FETCH_ASSOC)->willReturn([$this->row(), $this->row(['id' => '2'])]);
        $this->pdo->prepare(Argument::that(
            fn (string $sql): bool => str_contains($sql, 'WHERE active = 1 ORDER BY id LIMIT ? OFFSET ?')
        ))->willReturn($stmt->reveal())->shouldBeCalledOnce();

        $result = $this->repo->findAll(3, 10);

        $this->assertSame([[1, 10, PDO::PARAM_INT], [2, 20, PDO::PARAM_INT]], $binds);
        $this->assertCount(2, $result);
        $this->assertSame(2, $result[1]->id);
    }

    public function testFindAllClampsPageToOne(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetchAll(PDO::FETCH_ASSOC)->willReturn([]);
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());

        $this->assertSame([], $this->repo->findAll(0, 5));
        $this->assertSame([2, 0, PDO::PARAM_INT], $binds[1]);
    }

    public function testSearchWithQueryAndCategory(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetchAll(PDO::FETCH_ASSOC)->willReturn([$this->row()]);
        $captured = '';
        $this->pdo->prepare(Argument::that(function (string $sql) use (&$captured): bool {
            $captured = $sql;

            return true;
        }))->willReturn($stmt->reveal());

        $result = $this->repo->search('  widget  ', 'Cat', 2, 5);

        $this->assertStringContainsString(
            'AND MATCH(name, description, category) AGAINST (? IN NATURAL LANGUAGE MODE)',
            $captured
        );
        $this->assertStringContainsString('AND category = ?', $captured);
        $this->assertStringContainsString('ORDER BY MATCH(', $captured);
        $this->assertStringEndsWith('LIMIT ? OFFSET ?', $captured);
        $this->assertSame([
            [1, 'widget', PDO::PARAM_STR],
            [2, 'Cat', PDO::PARAM_STR],
            [3, 'widget', PDO::PARAM_STR],
            [4, 5, PDO::PARAM_INT],
            [5, 5, PDO::PARAM_INT],
        ], $binds);
        $this->assertCount(1, $result);
    }

    public function testSearchBlankQueryOmitsFullTextAndOrdersById(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetchAll(PDO::FETCH_ASSOC)->willReturn([]);
        $captured = '';
        $this->pdo->prepare(Argument::that(function (string $sql) use (&$captured): bool {
            $captured = $sql;

            return true;
        }))->willReturn($stmt->reveal());

        $this->repo->search('   ', null, 1, 20);

        $this->assertStringNotContainsString('MATCH', $captured);
        $this->assertStringNotContainsString('category = ?', $captured);
        $this->assertStringContainsString('ORDER BY id LIMIT ? OFFSET ?', $captured);
        $this->assertSame([[1, 20, PDO::PARAM_INT], [2, 0, PDO::PARAM_INT]], $binds);
    }

    public function testSearchBlankQueryWithCategory(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetchAll(PDO::FETCH_ASSOC)->willReturn([]);
        $this->pdo->prepare(Argument::containingString('AND category = ?'))->willReturn($stmt->reveal());

        $this->repo->search('', 'Books', 2, 10);

        $this->assertSame([[1, 'Books', PDO::PARAM_STR], [2, 10, PDO::PARAM_INT], [3, 10, PDO::PARAM_INT]], $binds);
    }

    public function testFindByIdHydratesProduct(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetch(PDO::FETCH_ASSOC)->willReturn($this->row());
        $this->pdo->prepare(Argument::containingString('FROM products WHERE id = ?'))->willReturn($stmt->reveal());

        $p = $this->repo->findById(1);

        $this->assertSame([[1, 1, PDO::PARAM_INT]], $binds);
        $this->assertSame(1, $p->id);
        $this->assertSame('SKU-1', $p->sku);
        $this->assertSame('Widget', $p->name);
        $this->assertSame('Desc', $p->description);
        $this->assertSame('Cat', $p->category);
        $this->assertSame(1999, $p->priceCents);
        $this->assertSame(5, $p->stock);
        $this->assertSame(1.5, $p->weightKg);
        $this->assertTrue($p->active);
        $this->assertSame('2024-01-01 00:00:00', $p->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame('2024-01-02 00:00:00', $p->updatedAt->format('Y-m-d H:i:s'));
    }

    public function testHydrationHandlesNullCategoryDescriptionAndWeight(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetch(PDO::FETCH_ASSOC)->willReturn(
            $this->row([
                'description' => null, 'category' => null, 'weight_kg' => null, 'active' => '0', 'price' => '0.05',
            ])
        );
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());

        $p = $this->repo->findById(1);

        $this->assertNull($p->description);
        $this->assertNull($p->category);
        $this->assertNull($p->weightKg);
        $this->assertFalse($p->active);
        $this->assertSame(5, $p->priceCents);
    }

    public function testFindByIdThrowsWhenMissing(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetch(PDO::FETCH_ASSOC)->willReturn(false);
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());

        $this->expectException(ProductNotFoundException::class);
        $this->repo->findById(99);
    }

    public function testFindBySkuBindsSkuAndHydrates(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetch(PDO::FETCH_ASSOC)->willReturn($this->row());
        $this->pdo->prepare(Argument::containingString('WHERE sku = ?'))->willReturn($stmt->reveal());

        $p = $this->repo->findBySku('SKU-1');

        $this->assertSame([[1, 'SKU-1', PDO::PARAM_STR]], $binds);
        $this->assertSame('SKU-1', $p->sku);
    }

    public function testFindBySkuThrowsWhenMissing(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetch(PDO::FETCH_ASSOC)->willReturn(false);
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());

        $this->expectException(ProductNotFoundException::class);
        $this->expectExceptionMessage('Product with sku "NOPE" not found.');
        $this->repo->findBySku('NOPE');
    }

    public function testFindByIdsBuildsPlaceholdersAndDedupes(): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->fetchAll(PDO::FETCH_ASSOC)->willReturn([$this->row(), $this->row(['id' => '3'])]);
        $this->pdo->prepare(Argument::containingString('WHERE id IN (?, ?, ?) ORDER BY id'))
            ->willReturn($stmt->reveal())->shouldBeCalledOnce();

        $result = $this->repo->findByIds([1, 3, 1, 5]);

        $this->assertSame([
            [1, 1, PDO::PARAM_INT],
            [2, 3, PDO::PARAM_INT],
            [3, 5, PDO::PARAM_INT],
        ], $binds);
        $this->assertCount(2, $result);
    }

    public function testFindByIdsWithEmptyListSkipsQuery(): void
    {
        $this->pdo->prepare(Argument::any())->shouldNotBeCalled();

        $this->assertSame([], $this->repo->findByIds([]));
    }

    public function testSaveInsertsNewProductAndReloadsIt(): void
    {
        $insertBinds = [];
        $insert = $this->stmt($insertBinds);
        $selectBinds = [];
        $select = $this->stmt($selectBinds);
        $select->fetch(PDO::FETCH_ASSOC)->willReturn($this->row(['id' => '12']));
        $this->pdo->prepare(Argument::containingString('INSERT INTO products'))
            ->willReturn($insert->reveal())->shouldBeCalledOnce();
        $this->pdo->prepare(Argument::containingString('WHERE id = ?'))->willReturn($select->reveal());
        $this->pdo->lastInsertId()->willReturn('12');

        $saved = $this->repo->save($this->product());

        $this->assertSame(12, $saved->id);
        $this->assertSame([
            [1, 'SKU-1', PDO::PARAM_STR],
            [2, 'Widget', PDO::PARAM_STR],
            [3, 'Desc', PDO::PARAM_STR],
            [4, 'Cat', PDO::PARAM_STR],
            [5, '19.99', PDO::PARAM_STR],
            [6, 5, PDO::PARAM_INT],
            [7, '1.500', PDO::PARAM_STR],
            [8, 1, PDO::PARAM_INT],
        ], $insertBinds);
        $this->assertSame([[1, 12, PDO::PARAM_INT]], $selectBinds);
    }

    public function testSaveBindsNullsForOptionalFields(): void
    {
        $insertBinds = [];
        $insert = $this->stmt($insertBinds);
        $selectBinds = [];
        $select = $this->stmt($selectBinds);
        $select->fetch(PDO::FETCH_ASSOC)->willReturn($this->row());
        $this->pdo->prepare(Argument::containingString('INSERT INTO'))->willReturn($insert->reveal());
        $this->pdo->prepare(Argument::containingString('SELECT'))->willReturn($select->reveal());
        $this->pdo->lastInsertId()->willReturn('1');
        $now = new DateTimeImmutable();

        $this->repo->save(new Product(null, 'S', 'N', null, null, 100, 0, null, false, $now, $now));

        $this->assertSame([3, null, PDO::PARAM_NULL], $insertBinds[2]);
        $this->assertSame([4, null, PDO::PARAM_NULL], $insertBinds[3]);
        $this->assertSame([5, '1.00', PDO::PARAM_STR], $insertBinds[4]);
        $this->assertSame([7, null, PDO::PARAM_NULL], $insertBinds[6]);
        $this->assertSame([8, 0, PDO::PARAM_INT], $insertBinds[7]);
    }

    public function testSaveUpdatesExistingProduct(): void
    {
        $updateBinds = [];
        $update = $this->stmt($updateBinds);
        $selectBinds = [];
        $select = $this->stmt($selectBinds);
        $select->fetch(PDO::FETCH_ASSOC)->willReturn($this->row(['id' => '4']));
        $this->pdo->prepare(Argument::containingString('UPDATE products SET sku = ?'))
            ->willReturn($update->reveal())->shouldBeCalledOnce();
        $this->pdo->prepare(Argument::containingString('SELECT'))->willReturn($select->reveal());
        $this->pdo->lastInsertId()->shouldNotBeCalled();

        $saved = $this->repo->save($this->product(4));

        $this->assertSame(4, $saved->id);
        $this->assertCount(9, $updateBinds);
        $this->assertSame([9, 4, PDO::PARAM_INT], $updateBinds[8]);
    }

    public function testSaveUpdateOfMissingRowThrowsNotFound(): void
    {
        $binds = [];
        $update = $this->stmt($binds);
        $selectBinds = [];
        $select = $this->stmt($selectBinds);
        $select->fetch(PDO::FETCH_ASSOC)->willReturn(false);
        $this->pdo->prepare(Argument::containingString('UPDATE'))->willReturn($update->reveal());
        $this->pdo->prepare(Argument::containingString('SELECT'))->willReturn($select->reveal());

        $this->expectException(ProductNotFoundException::class);
        $this->repo->save($this->product(77));
    }

    public function testSaveTranslatesDuplicateSkuError(): void
    {
        $e = new PDOException("Duplicate entry 'SKU-1' for key 'products.uq_products_sku'", 0);
        $e->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $this->setPdoCode($e, '23000');
        $stmt = $this->prophesize(PDOStatement::class);
        $stmt->bindValue(Argument::cetera())->willReturn(true);
        $stmt->execute()->willThrow($e);
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());

        try {
            $this->repo->save($this->product());
            $this->fail('Expected DuplicateSkuException');
        } catch (DuplicateSkuException $ex) {
            $this->assertSame('SKU-1', $ex->sku);
        }
    }

    /**
     * @return array<string, array{0: string, 1: array<int, mixed>|null, 2: string}>
     */
    public function nonDuplicateErrors(): array
    {
        return [
            'other sqlstate' => ['42S02', ['42S02', 1146, 'x'], 'Table uq_products_sku missing'],
            'other driver code' => ['23000', ['23000', 1048, 'x'], 'Column uq_products_sku null'],
            'other unique key' => ['23000', ['23000', 1062, 'x'], "Duplicate entry for key 'other_key'"],
        ];
    }

    /**
     * @dataProvider nonDuplicateErrors
     * @param array<int, mixed>|null $errorInfo
     */
    public function testSaveRethrowsOtherPdoExceptions(string $code, ?array $errorInfo, string $message): void
    {
        $e = new PDOException($message);
        $e->errorInfo = $errorInfo;
        $this->setPdoCode($e, $code);
        $stmt = $this->prophesize(PDOStatement::class);
        $stmt->bindValue(Argument::cetera())->willReturn(true);
        $stmt->execute()->willThrow($e);
        $this->pdo->prepare(Argument::any())->willReturn($stmt->reveal());

        try {
            $this->repo->save($this->product());
            $this->fail('Expected PDOException');
        } catch (PDOException $ex) {
            $this->assertSame($e, $ex);
        }
    }

    public function testDeactivateUpdatesAfterExistenceCheck(): void
    {
        $selectBinds = [];
        $select = $this->stmt($selectBinds);
        $select->fetch(PDO::FETCH_ASSOC)->willReturn($this->row());
        $updateBinds = [];
        $update = $this->stmt($updateBinds);
        $this->pdo->prepare(Argument::containingString('SELECT'))->willReturn($select->reveal());
        $this->pdo->prepare('UPDATE products SET active = 0 WHERE id = ?')
            ->willReturn($update->reveal())->shouldBeCalledOnce();

        $this->repo->deactivate(1);

        $this->assertSame([[1, 1, PDO::PARAM_INT]], $updateBinds);
        $update->execute()->shouldHaveBeenCalledOnce();
    }

    public function testDeactivateThrowsWhenMissing(): void
    {
        $binds = [];
        $select = $this->stmt($binds);
        $select->fetch(PDO::FETCH_ASSOC)->willReturn(false);
        $this->pdo->prepare(Argument::containingString('SELECT'))->willReturn($select->reveal());
        $this->pdo->prepare(Argument::containingString('UPDATE'))->shouldNotBeCalled();

        $this->expectException(ProductNotFoundException::class);
        $this->repo->deactivate(5);
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public function rowCounts(): array
    {
        return ['updated' => [1, true], 'none updated' => [0, false]];
    }

    /** @dataProvider rowCounts */
    public function testDecrementStockReflectsRowCount(int $rowCount, bool $expected): void
    {
        $binds = [];
        $stmt = $this->stmt($binds);
        $stmt->rowCount()->willReturn($rowCount);
        $this->pdo->prepare(Argument::containingString('SET stock = stock - ? WHERE id = ? AND stock >= ?'))
            ->willReturn($stmt->reveal());

        $this->assertSame($expected, $this->repo->decrementStock(8, 3));
        $this->assertSame([
            [1, 3, PDO::PARAM_INT],
            [2, 8, PDO::PARAM_INT],
            [3, 3, PDO::PARAM_INT],
        ], $binds);
    }

    private function setPdoCode(PDOException $e, string $code): void
    {
        $prop = new \ReflectionProperty(\Exception::class, 'code');
        $prop->setAccessible(true);
        $prop->setValue($e, $code);
    }
}
