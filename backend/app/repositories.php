<?php

declare(strict_types=1);

use App\Application\Settings\SettingsInterface;
use App\Domain\Order\OrderRepositoryInterface;
use App\Domain\Payment\PaymentGatewayInterface;
use App\Domain\Persistence\TransactionManagerInterface;
use App\Domain\Product\ProductRepositoryInterface;
use App\Infrastructure\Order\MySqlOrderRepository;
use App\Infrastructure\Payment\FakePaymentGateway;
use App\Infrastructure\Persistence\PdoTransactionManager;
use App\Infrastructure\Product\MySqlProductRepository;
use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

use function DI\autowire;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        PDO::class => function (ContainerInterface $c) {
            $db = $c->get(SettingsInterface::class)->get('db');
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'],
                $db['port'],
                $db['name'],
                $db['charset']
            );

            return new PDO($dsn, $db['user'], $db['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        },
        ProductRepositoryInterface::class => autowire(MySqlProductRepository::class),
        OrderRepositoryInterface::class => autowire(MySqlOrderRepository::class),
        TransactionManagerInterface::class => autowire(PdoTransactionManager::class),
        PaymentGatewayInterface::class => autowire(FakePaymentGateway::class),
    ]);
};
