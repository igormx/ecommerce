<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domain\Product\Exception\InsufficientStockException;
use Tests\Support\MySqlIntegrationTestCase;

/**
 * Real parallelism: each purchase runs in its own PHP process with its own
 * MySQL connection, all released at the same instant. Needs a test MySQL
 * (see MySqlIntegrationTestCase / `make test-concurrency`).
 */
class PurchaseConcurrencyTest extends MySqlIntegrationTestCase
{
    public function testTwentyParallelPurchasesOfFiveInStockYieldExactlyFivePaidOrders(): void
    {
        $id = $this->insertProduct('LIM-1', 5);

        $results = $this->runParallel(array_fill(0, 20, [$id => 1]));

        $counts = $this->tally($results);
        $this->assertSame(5, $counts['PAID'] ?? 0, json_encode($results));
        $this->assertSame(15, $counts[InsufficientStockException::class] ?? 0, json_encode($results));
        $this->assertSame(20, array_sum($counts), 'unexpected outcomes: ' . json_encode($counts));
        $this->assertSame(0, $this->stockOf($id));
        $this->assertSame(5, $this->scalar('SELECT COUNT(*) FROM orders WHERE status = "PAID"'));
        $this->assertSame(5, $this->scalar('SELECT COALESCE(SUM(quantity), 0) FROM order_items'));
    }

    public function testOppositeLineOrderAcrossConcurrentCartsNeitherDeadlocksNorOverSells(): void
    {
        $a = $this->insertProduct('A-1', 10);
        $b = $this->insertProduct('B-1', 10);

        // Carts list the two lines in opposite order; the service sorts by id
        // before decrementing so the row locks are always taken in the same order.
        $jobs = [];
        for ($i = 0; $i < 20; $i++) {
            $jobs[] = $i % 2 === 0 ? [$a => 1, $b => 1] : [$b => 1, $a => 1];
        }

        $results = $this->runParallel($jobs);

        $counts = $this->tally($results);
        $this->assertSame(10, $counts['PAID'] ?? 0, json_encode($results));
        $this->assertSame(10, $counts[InsufficientStockException::class] ?? 0, json_encode($results));
        $this->assertSame(20, array_sum($counts), 'unexpected outcomes (deadlock?): ' . json_encode($counts));
        $this->assertSame(0, $this->stockOf($a));
        $this->assertSame(0, $this->stockOf($b));
    }

    public function testHarnessDetectsOverSellingWithANaiveDecrement(): void
    {
        $id = $this->insertProduct('NAIVE-1', 5);

        $results = $this->runParallel(array_fill(0, 20, [$id => 1]), true);

        $paid = $this->tally($results)['PAID'] ?? 0;
        $this->assertGreaterThan(5, $paid, 'the naive read-then-write decrement should oversell');
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /**
     * @param list<array<int, int>> $jobs productId => quantity, one entry per worker
     * @return list<array<string, mixed>>
     */
    private function runParallel(array $jobs, bool $naive = false): array
    {
        $script = __DIR__ . '/purchase_worker.php';
        $workers = [];
        foreach ($jobs as $lines) {
            $payload = (string) json_encode(['lines' => $lines, 'naive' => $naive]);
            $process = proc_open(
                [PHP_BINARY, $script, $payload],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null
            );
            $this->assertIsResource($process);
            $workers[] = ['process' => $process, 'pipes' => $pipes];
        }

        // Barrier: wait until every worker is connected, then release them together.
        foreach ($workers as $w) {
            if (fgets($w['pipes'][1]) !== "READY\n") {
                $this->fail('worker failed to start: ' . stream_get_contents($w['pipes'][2]));
            }
        }
        foreach ($workers as $w) {
            fwrite($w['pipes'][0], "go\n");
            fflush($w['pipes'][0]);
        }

        $results = [];
        foreach ($workers as $w) {
            $line = stream_get_contents($w['pipes'][1]);
            $decoded = json_decode((string) $line, true);
            $results[] = is_array($decoded)
                ? $decoded
                : ['outcome' => 'ERROR', 'class' => 'worker crashed', 'message' => stream_get_contents($w['pipes'][2])];
            foreach ($w['pipes'] as $pipe) {
                fclose($pipe);
            }
            proc_close($w['process']);
        }

        return $results;
    }

    /**
     * Counts outcomes: PAID, FAILED or the exception class of an ERROR.
     *
     * @param list<array<string, mixed>> $results
     * @return array<string, int>
     */
    private function tally(array $results): array
    {
        $counts = [];
        foreach ($results as $r) {
            $key = $r['outcome'] === 'ERROR' ? (string) $r['class'] : (string) $r['outcome'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }
}
