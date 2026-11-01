<?php

declare(strict_types=1);

use App\Application\Settings\Settings;
use App\Application\Settings\SettingsInterface;
use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {

    // Global Settings Object
    $containerBuilder->addDefinitions([
        SettingsInterface::class => function () {
            return new Settings([
                'displayErrorDetails' => true, // Should be set to false in production
                'logError'            => false,
                'logErrorDetails'     => false,
                'db' => [
                    'host'     => getenv('DB_HOST') ?: 'db',
                    'port'     => (int) (getenv('DB_PORT') ?: 3306),
                    'name'     => getenv('DB_NAME') ?: 'ecommerce',
                    'user'     => getenv('DB_USER') ?: 'ecommerce',
                    'password' => getenv('DB_PASSWORD') ?: 'ecommerce',
                    'charset'  => 'utf8mb4',
                ],
                'logger' => [
                    'name' => 'slim-app',
                    'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
                    'level' => Logger::DEBUG,
                ],
            ]);
        }
    ]);
};
