<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Purchase;

use App\Application\Actions\ActionError;
use App\Application\Services\PurchaseService;
use App\Domain\Order\OrderRepositoryInterface;
use App\Domain\Payment\PaymentGatewayInterface;
use App\Domain\Persistence\TransactionManagerInterface;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Tests\Support\InMemoryOrderRepository;
use Tests\Support\InMemoryProductRepository;
use Tests\Support\PassthroughTransactionManager;
use Tests\Support\StubPaymentGateway;
use Tests\TestCase;

class PurchaseEndpointsTest extends TestCase
{
    private InMemoryProductRepository $products;
    private InMemoryOrderRepository $orders;

    protected function setUp(): void
    {
        $this->products = new InMemoryProductRepository(
            $this->product(1, 'HAM-1', 'Hammer', 1000, 5),
            $this->product(2, 'SAW-1', 'Saw', 250, 10),
            $this->product(3, 'OLD-1', 'Retired', 100, 10, false),
        );
        $this->orders = new InMemoryOrderRepository();
    }

    private function product(int $id, string $sku, string $name, int $price, int $stock, bool $active = true): Product
    {
        return new Product(
            $id,
            $sku,
            $name,
            null,
            null,
            $price,
            $stock,
            null,
            $active,
            new DateTimeImmutable('2024-01-01T10:00:00+00:00'),
            new DateTimeImmutable('2024-01-02T11:30:00+00:00'),
        );
    }

    private function app(bool $approve = true): App
    {
        return $this->getAppInstance([
            ProductRepositoryInterface::class => $this->products,
            OrderRepositoryInterface::class => $this->orders,
            PaymentGatewayInterface::class => new StubPaymentGateway($approve),
            TransactionManagerInterface::class => new PassthroughTransactionManager(),
        ]);
    }

    /**
     * @return array{status: int, body: array, raw: string}
     */
    private function handle(ServerRequestInterface $request, bool $approve = true): array
    {
        $response = $this->app($approve)->handle($request);
        $raw = (string) $response->getBody();
        $body = json_decode($raw, true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : [], 'raw' => $raw];
    }

    /**
     * @param array<array-key, mixed> $body
     * @return array{status: int, body: array, raw: string}
     */
    private function purchase(array $body, bool $approve = true): array
    {
        return $this->handle($this->createRequest('POST', '/purchases')->withParsedBody($body), $approve);
    }

    /**
     * @return array{status: int, body: array, raw: string}
     */
    private function purchaseRaw(string $rawBody): array
    {
        $request = $this->createRequest('POST', '/purchases', [
            'HTTP_ACCEPT' => 'application/json',
            'Content-Type' => 'application/json',
        ]);
        $request->getBody()->write($rawBody);
        $request->getBody()->rewind();

        return $this->handle($request);
    }

    public function testPaidPurchaseReturns201WithOrder(): void
    {
        $result = $this->purchase(['items' => [
            ['productId' => 1, 'quantity' => 2],
            ['productId' => 2, 'quantity' => 4],
        ]]);

        $this->assertSame(201, $result['status']);
        $data = $result['body']['data'];
        $this->assertSame('PAID', $data['status']);
        $this->assertSame(3000, $data['totalCents']);
        $this->assertCount(2, $data['items']);
        $this->assertSame(
            ['productId' => 1, 'sku' => 'HAM-1', 'name' => 'Hammer', 'quantity' => 2, 'unitPriceCents' => 1000],
            $data['items'][0],
        );
        $this->assertSame(3, $this->products->findById(1)->stock);
        $this->assertSame(6, $this->products->findById(2)->stock);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T/', $data['createdAt']);
    }

    public function testDeclinedPaymentStillReturns201WithFailedOrder(): void
    {
        $result = $this->purchase(['items' => [['productId' => 1, 'quantity' => 1]]], false);

        $this->assertSame(201, $result['status']);
        $this->assertSame('FAILED', $result['body']['data']['status']);
        $this->assertSame(1000, $result['body']['data']['totalCents']);
        $this->assertSame(1, $this->orders->count());
    }

    public function testAcceptsRawJsonBody(): void
    {
        $result = $this->purchaseRaw('{"items":[{"productId":2,"quantity":1}]}');

        $this->assertSame(201, $result['status']);
        $this->assertSame('PAID', $result['body']['data']['status']);
    }

    public function testInsufficientStockReturns409(): void
    {
        $result = $this->purchase(['items' => [['productId' => 1, 'quantity' => 6]]]);

        $this->assertSame(409, $result['status']);
        $this->assertSame(ActionError::INSUFFICIENT_STOCK, $result['body']['error']['type']);
        $this->assertSame(5, $this->products->findById(1)->stock);
    }

    public function testUnknownProductReturns404(): void
    {
        $result = $this->purchase(['items' => [['productId' => 999, 'quantity' => 1]]]);

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
    }

    public function testInactiveProductReturns404(): void
    {
        $result = $this->purchase(['items' => [['productId' => 3, 'quantity' => 1]]]);

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
        $this->assertSame(10, $this->products->findById(3)->stock);
    }

    public function testAcceptsBoundaryValues(): void
    {
        $this->products->save($this->product(1, 'HAM-1', 'Hammer', 1, PurchaseService::MAX_QUANTITY));

        $result = $this->purchase(['items' => [['productId' => 1, 'quantity' => PurchaseService::MAX_QUANTITY]]]);

        $this->assertSame(201, $result['status']);
    }

    /**
     * @return array<string, array{0: array<array-key, mixed>, 1: string}>
     */
    public static function invalidPayloads(): array
    {
        $line = ['productId' => 1, 'quantity' => 1];
        $tooMany = [];
        for ($i = 1; $i <= PurchaseService::MAX_LINES + 1; $i++) {
            $tooMany[] = ['productId' => $i, 'quantity' => 1];
        }

        return [
            'missing items' => [['other' => 1], 'items'],
            'empty items' => [['items' => []], 'items'],
            'items not a list' => [['items' => 'abc'], 'items'],
            'items is an object' => [['items' => ['a' => $line]], 'items'],
            'line not an object' => [['items' => [5]], 'items.0'],
            'string product id' => [['items' => [['productId' => '1', 'quantity' => 1]]], 'items.0.productId'],
            'float product id' => [['items' => [['productId' => 1.5, 'quantity' => 1]]], 'items.0.productId'],
            'zero product id' => [['items' => [['productId' => 0, 'quantity' => 1]]], 'items.0.productId'],
            'missing product id' => [['items' => [['quantity' => 1]]], 'items.0.productId'],
            'zero quantity' => [['items' => [['productId' => 1, 'quantity' => 0]]], 'items.0.quantity'],
            'negative quantity' => [['items' => [['productId' => 1, 'quantity' => -1]]], 'items.0.quantity'],
            'string quantity' => [['items' => [['productId' => 1, 'quantity' => '2']]], 'items.0.quantity'],
            'quantity over max' => [
                ['items' => [['productId' => 1, 'quantity' => PurchaseService::MAX_QUANTITY + 1]]],
                'items.0.quantity',
            ],
            'duplicate product id' => [['items' => [$line, $line]], 'items'],
            'too many lines' => [['items' => $tooMany], 'items'],
            'unknown line key' => [['items' => [$line + ['price' => 1]]], 'items.0.price'],
            'unknown top-level key' => [['items' => [$line], 'coupon' => 'X'], 'coupon'],
        ];
    }

    /**
     * @dataProvider invalidPayloads
     * @param array<array-key, mixed> $payload
     */
    public function testInvalidPayloadReturns400(array $payload, string $field): void
    {
        $result = $this->purchase($payload);

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
        $this->assertArrayHasKey($field, $result['body']['error']['fields']);
        $this->assertSame(0, $this->orders->count());
        $this->assertSame(5, $this->products->findById(1)->stock);
    }

    public function testReportsEveryBadLineAtOnce(): void
    {
        $result = $this->purchase(['items' => [
            ['productId' => 0, 'quantity' => 1],
            ['productId' => 1, 'quantity' => 0],
        ]]);

        $this->assertSame(400, $result['status']);
        $this->assertArrayHasKey('items.0.productId', $result['body']['error']['fields']);
        $this->assertArrayHasKey('items.1.quantity', $result['body']['error']['fields']);
    }

    public function testMalformedJsonReturns400(): void
    {
        $result = $this->purchaseRaw('{"items": [');

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
    }

    public function testEmptyJsonObjectReturns400(): void
    {
        $this->assertSame(400, $this->purchaseRaw('{}')['status']);
    }

    public function testViewOrderReturnsPurchasedOrder(): void
    {
        $created = $this->purchase(['items' => [['productId' => 2, 'quantity' => 2]]]);
        $id = $created['body']['data']['id'];

        $result = $this->handle($this->createRequest('GET', '/orders/' . $id));

        $this->assertSame(200, $result['status']);
        $this->assertSame($created['body']['data'], $result['body']['data']);
    }

    public function testViewOrderUnknownIdReturns404(): void
    {
        $result = $this->handle($this->createRequest('GET', '/orders/42'));

        $this->assertSame(404, $result['status']);
    }

    public function testViewOrderBadIdReturns400(): void
    {
        $this->assertSame(400, $this->handle($this->createRequest('GET', '/orders/abc'))['status']);
        $this->assertSame(400, $this->handle($this->createRequest('GET', '/orders/0'))['status']);
    }
}
