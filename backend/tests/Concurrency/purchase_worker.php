<?php

declare(strict_types=1);

// Worker process for PurchaseConcurrencyTest: one process = one MySQL connection.
//   argv[1] JSON {"lines": {productId: qty, ...}, "naive": bool}
// Protocol: print READY, block until a line arrives on stdin ("go"), purchase,
// print one JSON line with the outcome.

use App\Application\Services\PurchaseService;
use App\Infrastructure\Order\MySqlOrderRepository;
use App\Infrastructure\Payment\FakePaymentGateway;
use App\Infrastructure\Persistence\PdoTransactionManager;
use App\Infrastructure\Product\MySqlProductRepository;
use Tests\Support\MySqlIntegrationTestCase;
use Tests\Support\NaiveProductRepository;

require __DIR__ . '/../../vendor/autoload.php';

$job = json_decode((string) ($argv[1] ?? '{}'), true);
$pdo = MySqlIntegrationTestCase::connect();
$products = !empty($job['naive']) ? new NaiveProductRepository($pdo) : new MySqlProductRepository($pdo);
$service = new PurchaseService(
    $products,
    new MySqlOrderRepository($pdo),
    new FakePaymentGateway(),
    new PdoTransactionManager($pdo)
);

echo "READY\n";
fflush(STDOUT);
fgets(STDIN);

$lines = [];
foreach ($job['lines'] as $productId => $quantity) {
    $lines[(int) $productId] = (int) $quantity;
}

try {
    $order = $service->purchase($lines);
    $result = ['outcome' => $order->status->value, 'total' => $order->totalCents];
} catch (Throwable $e) {
    $result = ['outcome' => 'ERROR', 'class' => $e::class, 'message' => $e->getMessage()];
}

echo json_encode($result), "\n";
