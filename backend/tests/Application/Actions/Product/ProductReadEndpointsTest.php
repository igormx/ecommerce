<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Product;

use App\Application\Actions\ActionError;
use App\Domain\Product\Product;
use App\Domain\Product\ProductRepositoryInterface;
use DateTimeImmutable;
use Tests\Support\InMemoryProductRepository;
use Tests\TestCase;

class ProductReadEndpointsTest extends TestCase
{
    private function product(
        ?int $id,
        string $sku,
        string $name,
        ?string $category = 'Tools',
        bool $active = true
    ): Product {
        return new Product(
            $id,
            $sku,
            $name,
            'A fine ' . $name,
            $category,
            1999,
            5,
            1.5,
            $active,
            new DateTimeImmutable('2024-01-01T10:00:00+00:00'),
            new DateTimeImmutable('2024-01-02T11:30:00+00:00'),
        );
    }

    /**
     * @return array{status: int, body: array}
     */
    private function get(string $path, ?InMemoryProductRepository $repo = null): array
    {
        $repo ??= new InMemoryProductRepository(
            $this->product(1, 'HAM-1', 'Hammer'),
            $this->product(2, 'SAW-1', 'Saw'),
            $this->product(3, 'OLD-1', 'Retired', 'Tools', false),
            $this->product(4, 'MUG-1', 'Hammer Mug', 'Kitchen'),
        );
        $app = $this->getAppInstance([ProductRepositoryInterface::class => $repo]);

        // createRequest() treats its argument as a bare path, so split off the query string.
        [$route, $query] = array_pad(explode('?', $path, 2), 2, '');
        parse_str($query, $queryParams);
        $request = $this->createRequest('GET', $route)->withQueryParams($queryParams);

        $response = $app->handle($request);

        /** @var array $body */
        $body = json_decode((string) $response->getBody(), true);

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }

    public function testViewReturnsProductResource(): void
    {
        $result = $this->get('/products/1');

        $this->assertSame(200, $result['status']);
        $this->assertSame(200, $result['body']['statusCode']);
        $this->assertSame([
            'id' => 1,
            'sku' => 'HAM-1',
            'name' => 'Hammer',
            'description' => 'A fine Hammer',
            'category' => 'Tools',
            'priceCents' => 1999,
            'stock' => 5,
            'weightKg' => 1.5,
            'createdAt' => '2024-01-01T10:00:00+00:00',
            'updatedAt' => '2024-01-02T11:30:00+00:00',
        ], $result['body']['data']);
    }

    public function testViewUnknownIdIs404(): void
    {
        $result = $this->get('/products/999');

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
    }

    public function testViewInactiveIdIs404(): void
    {
        $result = $this->get('/products/3');

        $this->assertSame(404, $result['status']);
        $this->assertSame(ActionError::RESOURCE_NOT_FOUND, $result['body']['error']['type']);
    }

    /**
     * @dataProvider invalidIdProvider
     */
    public function testViewInvalidIdIs400(string $path): void
    {
        $result = $this->get($path);

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIdProvider(): array
    {
        return ['non-numeric' => ['/products/abc'], 'zero' => ['/products/0']];
    }

    public function testListReturnsActiveProducts(): void
    {
        $result = $this->get('/products');

        $this->assertSame(200, $result['status']);
        $this->assertSame([1, 2, 4], array_column($result['body']['data'], 'id'));
    }

    public function testListPaginates(): void
    {
        $result = $this->get('/products?page=2&size=2');

        $this->assertSame(200, $result['status']);
        $this->assertSame([4], array_column($result['body']['data'], 'id'));
    }

    /**
     * @dataProvider invalidPagingProvider
     */
    public function testListInvalidPagingIs400(string $path, string $field): void
    {
        $result = $this->get($path);

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
        $this->assertArrayHasKey($field, $result['body']['error']['fields']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidPagingProvider(): array
    {
        return [
            'page zero' => ['/products?page=0', 'page'],
            'page non-numeric' => ['/products?page=abc', 'page'],
            'size over max' => ['/products?size=101', 'size'],
            'page int max' => ['/products?page=9223372036854775807', 'page'],
            'page overflowing digits' => ['/products?page=99999999999999999999', 'page'],
            'search page too large' => ['/products/search?q=x&page=100000000000000000', 'page'],
        ];
    }

    public function testSearchByTerm(): void
    {
        $result = $this->get('/products/search?q=hammer');

        $this->assertSame(200, $result['status']);
        $this->assertSame([1, 4], array_column($result['body']['data'], 'id'));
    }

    public function testSearchByTermAndCategory(): void
    {
        $result = $this->get('/products/search?q=hammer&category=Kitchen');

        $this->assertSame(200, $result['status']);
        $this->assertSame([4], array_column($result['body']['data'], 'id'));
    }

    public function testSearchIsNotShadowedByIdRoute(): void
    {
        // If `{id}` were registered first, "search" would fail id validation (400).
        $result = $this->get('/products/search?q=nothing-matches');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']['data']);
    }

    public function testSearchByCategoryOnlyReturnsFilteredPageWithMeta(): void
    {
        $result = $this->get('/products/search?category=Tools');

        $this->assertSame(200, $result['status']);
        $this->assertSame([1, 2], array_column($result['body']['data'], 'id'));
        $this->assertSame(['page' => 1, 'size' => 20, 'total' => 2], $result['body']['meta']);
    }

    public function testSearchByCategoryOnlyPaginates(): void
    {
        $result = $this->get('/products/search?category=Tools&page=2&size=1');

        $this->assertSame([2], array_column($result['body']['data'], 'id'));
        $this->assertSame(['page' => 2, 'size' => 1, 'total' => 2], $result['body']['meta']);
    }

    /**
     * @dataProvider neitherQNorCategoryProvider
     */
    public function testSearchWithNeitherQNorCategoryIs400(string $path): void
    {
        $result = $this->get($path);

        $this->assertSame(400, $result['status']);
        $this->assertSame(ActionError::VALIDATION_ERROR, $result['body']['error']['type']);
        $this->assertSame('Provide q or category.', $result['body']['error']['fields']['q']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function neitherQNorCategoryProvider(): array
    {
        return [
            'no params' => ['/products/search'],
            'empty q' => ['/products/search?q='],
            'whitespace q' => ['/products/search?q=%20%20'],
            'empty category' => ['/products/search?category='],
            'whitespace q and empty category' => ['/products/search?q=%20&category='],
            'whitespace category' => ['/products/search?category=%20'],
            'whitespace q and whitespace category' => ['/products/search?q=%20&category=%20%20'],
        ];
    }

    public function testSearchWithWhitespaceQAndCategoryBehavesLikeCategoryOnly(): void
    {
        $blank = $this->get('/products/search?q=%20%20&category=Kitchen');
        $categoryOnly = $this->get('/products/search?category=Kitchen');

        $this->assertSame(200, $blank['status']);
        $this->assertSame([4], array_column($blank['body']['data'], 'id'));
        $this->assertSame($categoryOnly['body']['data'], $blank['body']['data']);
        $this->assertSame($categoryOnly['body']['meta'], $blank['body']['meta']);
    }

    public function testSearchWithQAndWhitespaceCategoryBehavesLikeQOnly(): void
    {
        $blank = $this->get('/products/search?q=hammer&category=%20');
        $qOnly = $this->get('/products/search?q=hammer');

        $this->assertSame(200, $blank['status']);
        $this->assertNotEmpty($blank['body']['data']);
        $this->assertSame($qOnly['body']['data'], $blank['body']['data']);
        $this->assertSame($qOnly['body']['meta'], $blank['body']['meta']);
    }

    /**
     * @dataProvider overlongSearchParamProvider
     */
    public function testSearchOverlongParamIs400(string $path, string $field): void
    {
        $result = $this->get($path);

        $this->assertSame(400, $result['status']);
        $this->assertArrayHasKey($field, $result['body']['error']['fields']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overlongSearchParamProvider(): array
    {
        return [
            'q 256' => ['/products/search?q=' . str_repeat('a', 256), 'q'],
            'category 101' => ['/products/search?category=' . str_repeat('a', 101), 'category'],
            'q 256 with category' => ['/products/search?category=Tools&q=' . str_repeat('a', 256), 'q'],
        ];
    }

    public function testSearchInvalidPagingIs400(): void
    {
        $this->assertSame(400, $this->get('/products/search?q=a&size=101')['status']);
        $this->assertSame(400, $this->get('/products/search?q=a&page=0')['status']);
    }

    public function testListIncludesMetaWithTotalAndEchoesPageAndSize(): void
    {
        $result = $this->get('/products?page=2&size=2');

        $this->assertSame(200, $result['status']);
        $this->assertSame([4], array_column($result['body']['data'], 'id'));
        $this->assertSame(['page' => 2, 'size' => 2, 'total' => 3], $result['body']['meta']);
    }

    public function testListPagePastEndReturnsEmptyDataWithCorrectTotal(): void
    {
        $result = $this->get('/products?page=5&size=2');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']['data']);
        $this->assertSame(['page' => 5, 'size' => 2, 'total' => 3], $result['body']['meta']);
    }

    public function testSearchIncludesMetaWithTotalAndEchoesPageAndSize(): void
    {
        $result = $this->get('/products/search?q=hammer&page=1&size=10');

        $this->assertSame(200, $result['status']);
        $this->assertSame([1, 4], array_column($result['body']['data'], 'id'));
        $this->assertSame(['page' => 1, 'size' => 10, 'total' => 2], $result['body']['meta']);
    }

    public function testSearchPagePastEndReturnsEmptyDataWithCorrectTotal(): void
    {
        $result = $this->get('/products/search?q=hammer&page=3&size=1');

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']['data']);
        $this->assertSame(['page' => 3, 'size' => 1, 'total' => 2], $result['body']['meta']);
    }

    public function testCategoriesReturnsSortedListOfStrings(): void
    {
        $result = $this->get('/categories');

        $this->assertSame(200, $result['status']);
        $this->assertSame(200, $result['body']['statusCode']);
        $this->assertSame(['Kitchen', 'Tools'], $result['body']['data']);
    }

    public function testCategoriesOmitsCategoryOnceItsOnlyProductIsDeactivated(): void
    {
        $repo = new InMemoryProductRepository(
            $this->product(1, 'HAM-1', 'Hammer'),
            $this->product(2, 'MUG-1', 'Mug', 'Kitchen'),
        );
        $repo->deactivate(2);

        $result = $this->get('/categories', $repo);

        $this->assertSame(['Tools'], $result['body']['data']);
    }

    public function testCategoriesIsEmptyListForEmptyDatabase(): void
    {
        $result = $this->get('/categories', new InMemoryProductRepository());

        $this->assertSame(200, $result['status']);
        $this->assertSame([], $result['body']['data']);
    }

    /**
     * @dataProvider nonPaginatedEndpointsProvider
     */
    public function testNonPaginatedEndpointsHaveNoMetaKey(string $path): void
    {
        $result = $this->get($path);

        $this->assertArrayNotHasKey('meta', $result['body']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonPaginatedEndpointsProvider(): array
    {
        return [
            'view' => ['/products/1'],
            'view missing (404)' => ['/products/999'],
            'categories' => ['/categories'],
            'search with neither q nor category (400)' => ['/products/search'],
        ];
    }
}
