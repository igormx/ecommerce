<?php

declare(strict_types=1);

use App\Application\Actions\Category\ListCategoriesAction;
use App\Application\Actions\Order\ViewOrderAction;
use App\Application\Actions\Product\CreateProductAction;
use App\Application\Actions\Product\DeactivateProductAction;
use App\Application\Actions\Product\ListProductsAction;
use App\Application\Actions\Product\SearchProductsAction;
use App\Application\Actions\Product\UpdateProductAction;
use App\Application\Actions\Product\ViewProductAction;
use App\Application\Actions\Purchase\CreatePurchaseAction;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Interfaces\RouteCollectorProxyInterface as Group;

return function (App $app) {
    $app->options('/{routes:.*}', function (Request $request, Response $response) {
        // CORS Pre-Flight OPTIONS Request Handler
        return $response;
    });

    $app->get('/', function (Request $request, Response $response) {
        $response->getBody()->write('Hello world!');
        return $response;
    });

    $app->get('/products', ListProductsAction::class);
    // Must be registered before `/products/{id}`, or "search" would match as an id.
    $app->get('/products/search', SearchProductsAction::class);
    $app->get('/products/{id}', ViewProductAction::class);
    $app->post('/products', CreateProductAction::class);
    $app->put('/products/{id}', UpdateProductAction::class);
    $app->delete('/products/{id}', DeactivateProductAction::class);

    $app->get('/categories', ListCategoriesAction::class);

    $app->post('/purchases', CreatePurchaseAction::class);
    $app->get('/orders/{id}', ViewOrderAction::class);
};
