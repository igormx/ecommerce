<?php

declare(strict_types=1);

namespace Tests\Application\Handlers;

use App\Application\Actions\ActionError;
use App\Application\Handlers\HttpErrorHandler;
use App\Application\Services\Exception\InvalidPurchaseException;
use App\Application\Services\Exception\ValidationException;
use App\Domain\Order\Exception\OrderNotFoundException;
use App\Domain\Product\Exception\DuplicateSkuException;
use App\Domain\Product\Exception\InsufficientStockException;
use App\Domain\Product\Exception\ProductNotFoundException;
use RuntimeException;
use Slim\Exception\HttpNotFoundException;
use Tests\TestCase;
use Throwable;

class HttpErrorHandlerTest extends TestCase
{
    /**
     * @return array{status: int, body: array}
     */
    private function handle(Throwable $exception): array
    {
        $app = $this->getAppInstance();
        $handler = new HttpErrorHandler($app->getCallableResolver(), $app->getResponseFactory());

        $request = $this->createRequest('GET', '/whatever');
        $response = $handler($request, $exception, false, false, false);

        /** @var array $body */
        $body = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }

    public function testValidationExceptionMapsTo400WithFields(): void
    {
        $result = $this->handle(new ValidationException(['sku' => 'is required']));

        $this->assertSame(400, $result['status']);
        $this->assertSame(400, $result['body']['statusCode']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
        $this->assertSame(['sku' => 'is required'], $result['body']['error']['fields']);
    }

    public function testValidationExceptionWithoutFieldErrorsOmitsFields(): void
    {
        // e.g. an unparseable JSON body: no per-field errors to report.
        $result = $this->handle(new ValidationException([]));

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
        $this->assertArrayNotHasKey('fields', $result['body']['error']);
    }

    public function testInvalidPurchaseExceptionMapsTo400(): void
    {
        $result = $this->handle(InvalidPurchaseException::emptyPurchase());

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
        $this->assertArrayNotHasKey('fields', $result['body']['error']);
    }

    public function testProductNotFoundMapsTo404ThroughHttpNotFoundException(): void
    {
        // Action::__invoke converts ProductNotFoundException (a
        // DomainRecordNotFoundException) into this before it ever reaches
        // the error handler.
        $result = $this->handle(new HttpNotFoundException(
            $this->createRequest('GET', '/products/1'),
            ProductNotFoundException::forId(1)->getMessage(),
        ));

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
    }

    public function testOrderNotFoundMapsTo404ThroughHttpNotFoundException(): void
    {
        $result = $this->handle(new HttpNotFoundException(
            $this->createRequest('GET', '/orders/1'),
            OrderNotFoundException::forId(1)->getMessage(),
        ));

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
    }

    public function testDuplicateSkuExceptionMapsTo409Conflict(): void
    {
        $result = $this->handle(new DuplicateSkuException('SKU-1'));

        $this->assertSame(409, $result['status']);
        $this->assertSame(ActionError::CONFLICT, $result['body']['error']['type']);
    }

    public function testInsufficientStockExceptionMapsTo409InsufficientStock(): void
    {
        $result = $this->handle(new InsufficientStockException(1, 5, 2));

        $this->assertSame(409, $result['status']);
        $this->assertSame(ActionError::INSUFFICIENT_STOCK, $result['body']['error']['type']);
    }

    public function testUnhandledExceptionMapsTo500ServerError(): void
    {
        $result = $this->handle(new RuntimeException('boom'));

        $this->assertSame(500, $result['status']);
        $this->assertSame(ActionError::SERVER_ERROR, $result['body']['error']['type']);
    }
}
