<?php

declare(strict_types=1);

// Worker for the forced-deadlock test: one process = one MySQL connection.
//   argv[1] JSON {"first": id, "second": id}
// Runs PdoTransactionManager::run (zero backoff). On the FIRST attempt only, it locks
// `first`, pauses so both workers hold their first row, then locks `second` - the two
// workers use opposite orders, so InnoDB must abort one with error 1213. The retry
// (attempt 2+) skips the pause and must succeed.

use App\Infrastructure\Persistence\PdoTransactionManager;
use Tests\Support\MySqlIntegrationTestCase;

require __DIR__ . '/../../vendor/autoload.php';

$job = json_decode((string) ($argv[1] ?? '{}'), true);
$pdo = MySqlIntegrationTestCase::connect();
$manager = new PdoTransactionManager($pdo, 3, 0);

echo "READY\n";
fflush(STDOUT);
fgets(STDIN);

$attempts = 0;
try {
    $manager->run(function () use ($pdo, $job, &$attempts): void {
        $attempts++;
        $lock = $pdo->prepare('SELECT stock FROM products WHERE id = ? FOR UPDATE');
        $lock->execute([(int) $job['first']]);
        $lock->fetchAll();
        if ($attempts === 1) {
            usleep(700_000);
        }
        $lock->execute([(int) $job['second']]);
        $lock->fetchAll();
        $pdo->prepare('UPDATE products SET stock = stock - 1 WHERE id IN (?, ?)')
            ->execute([(int) $job['first'], (int) $job['second']]);
    });
    $result = ['outcome' => 'OK', 'attempts' => $attempts];
} catch (Throwable $e) {
    $result = ['outcome' => 'ERROR', 'class' => $e::class, 'message' => $e->getMessage(), 'attempts' => $attempts];
}

echo json_encode($result), "\n";
