<?php

declare(strict_types=1);

namespace Tests\Application\Actions;

use App\Application\Actions\Action;
use App\Application\Actions\ActionPayload;
use App\Application\Services\Exception\ValidationException;
use DateTimeImmutable;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request as SlimRequest;
use Slim\Psr7\Uri;
use Tests\TestCase;

class ActionTest extends TestCase
{
    public function testActionSetsHttpCodeInRespond()
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        $logger = $container->get(LoggerInterface::class);

        $testAction = new class ($logger) extends Action {
            public function __construct(
                LoggerInterface $loggerInterface
            ) {
                parent::__construct($loggerInterface);
            }

            public function action(): Response
            {
                return $this->respond(
                    new ActionPayload(
                        202,
                        [
                            'willBeDoneAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM)
                        ]
                    )
                );
            }
        };

        $app->get('/test-action-response-code', $testAction);
        $request = $this->createRequest('GET', '/test-action-response-code');
        $response = $app->handle($request);

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testActionSetsHttpCodeRespondData()
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        $logger = $container->get(LoggerInterface::class);

        $testAction = new class ($logger) extends Action {
            public function __construct(
                LoggerInterface $loggerInterface
            ) {
                parent::__construct($loggerInterface);
            }

            public function action(): Response
            {
                return $this->respondWithData(
                    [
                        'willBeDoneAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM)
                    ],
                    202
                );
            }
        };

        $app->get('/test-action-response-code', $testAction);
        $request = $this->createRequest('GET', '/test-action-response-code');
        $response = $app->handle($request);

        $this->assertEquals(202, $response->getStatusCode());
    }

    public function testGetJsonBodyReturnsTheParsedBodyArray(): void
    {
        $request = $this->createRequest('POST', '/x')->withParsedBody(['sku' => 'ABC']);

        $result = $this->invokeProbe('getJsonBody', [], $request);

        $this->assertSame(['sku' => 'ABC'], $result);
    }

    public function testGetJsonBodyRejectsANullBody(): void
    {
        // addBodyParsingMiddleware() leaves getParsedBody() as null for a
        // malformed JSON body or an unsupported content type.
        $request = $this->createRequest('POST', '/x')->withParsedBody(null);

        $this->expectException(ValidationException::class);
        $this->invokeProbe('getJsonBody', [], $request);
    }

    public function testGetIntArgAcceptsAPositiveIntegerString(): void
    {
        $result = $this->invokeProbe('getIntArg', ['id'], $this->createRequest('GET', '/x'), ['id' => '5']);

        $this->assertSame(5, $result);
    }

    /**
     * @dataProvider invalidIntArgProvider
     */
    public function testGetIntArgRejectsNonPositiveIntegers(string $value): void
    {
        $this->expectException(ValidationException::class);
        $this->invokeProbe('getIntArg', ['id'], $this->createRequest('GET', '/x'), ['id' => $value]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidIntArgProvider(): array
    {
        return [
            'non-numeric' => ['abc'],
            'zero' => ['0'],
            'negative' => ['-1'],
            'float' => ['1.5'],
        ];
    }

    public function testGetPageDefaultsToOneWhenAbsent(): void
    {
        $result = $this->invokeProbe('getPage', [], $this->createQueryRequest('/x', []));

        $this->assertSame(1, $result);
    }

    public function testGetPageAcceptsAPositiveIntegerOverride(): void
    {
        $result = $this->invokeProbe('getPage', [], $this->createQueryRequest('/x', ['page' => '3']));

        $this->assertSame(3, $result);
    }

    /**
     * @dataProvider invalidPageProvider
     */
    public function testGetPageRejectsInvalidValues(string $value): void
    {
        $this->expectException(ValidationException::class);
        $this->invokeProbe('getPage', [], $this->createQueryRequest('/x', ['page' => $value]));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidPageProvider(): array
    {
        return [
            'zero' => ['0'],
            'non-numeric' => ['abc'],
            'above cap' => ['1000001'],
            'int max' => ['9223372036854775807'],
            'overflowing digit string' => ['99999999999999999999'],
        ];
    }

    public function testGetPageAcceptsTheCap(): void
    {
        $result = $this->invokeProbe('getPage', [], $this->createQueryRequest('/x', ['page' => '1000000']));

        $this->assertSame(1_000_000, $result);
    }

    public function testGetSizeDefaultsToTwentyWhenAbsent(): void
    {
        $result = $this->invokeProbe('getSize', [], $this->createQueryRequest('/x', []));

        $this->assertSame(20, $result);
    }

    public function testGetSizeAcceptsUpToTheMax(): void
    {
        $result = $this->invokeProbe('getSize', [], $this->createQueryRequest('/x', ['size' => '100']));

        $this->assertSame(100, $result);
    }

    public function testGetSizeRejectsOverTheMax(): void
    {
        $this->expectException(ValidationException::class);
        $this->invokeProbe('getSize', [], $this->createQueryRequest('/x', ['size' => '101']));
    }

    public function testGetOptionalSearchTermReturnsTheQParam(): void
    {
        $result = $this->invokeProbe('getOptionalSearchTerm', [], $this->createQueryRequest('/x', ['q' => 'shoe']));

        $this->assertSame('shoe', $result);
    }

    public function testGetOptionalSearchTermIsNullWhenAbsent(): void
    {
        $result = $this->invokeProbe('getOptionalSearchTerm', [], $this->createQueryRequest('/x', []));

        $this->assertNull($result);
    }

    /**
     * @dataProvider blankTermProvider
     */
    public function testGetOptionalSearchTermIsNullWhenBlank(string $blank): void
    {
        $result = $this->invokeProbe('getOptionalSearchTerm', [], $this->createQueryRequest('/x', ['q' => $blank]));

        $this->assertNull($result);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankTermProvider(): array
    {
        return ['empty' => [''], 'spaces' => ['   '], 'tab and newline' => ["\t\n"]];
    }

    public function testGetOptionalSearchTermRejectsANonString(): void
    {
        $this->expectException(ValidationException::class);
        $this->invokeProbe('getOptionalSearchTerm', [], $this->createQueryRequest('/x', ['q' => ['a']]));
    }

    public function testGetOptionalSearchTermRejectsAnOverlongValue(): void
    {
        $this->expectException(ValidationException::class);
        $this->invokeProbe(
            'getOptionalSearchTerm',
            [],
            $this->createQueryRequest('/x', ['q' => str_repeat('a', 256)]),
        );
    }

    public function testGetOptionalSearchTermAcceptsTheMaxLength(): void
    {
        $term = str_repeat('a', 255);
        $result = $this->invokeProbe('getOptionalSearchTerm', [], $this->createQueryRequest('/x', ['q' => $term]));

        $this->assertSame($term, $result);
    }

    public function testGetCategoryIsNullWhenAbsent(): void
    {
        $result = $this->invokeProbe('getCategory', [], $this->createQueryRequest('/x', []));

        $this->assertNull($result);
    }

    public function testGetCategoryReturnsTheValueWhenPresent(): void
    {
        $result = $this->invokeProbe('getCategory', [], $this->createQueryRequest('/x', ['category' => 'shoes']));

        $this->assertSame('shoes', $result);
    }

    /**
     * @dataProvider blankTermProvider
     */
    public function testGetCategoryIsNullWhenBlank(string $blank): void
    {
        $result = $this->invokeProbe('getCategory', [], $this->createQueryRequest('/x', ['category' => $blank]));

        $this->assertNull($result);
    }

    public function testGetCategoryAcceptsTheMaxLength(): void
    {
        $category = str_repeat('a', 100);
        $result = $this->invokeProbe('getCategory', [], $this->createQueryRequest('/x', ['category' => $category]));

        $this->assertSame($category, $result);
    }

    public function testGetCategoryRejectsAnOverlongValue(): void
    {
        $this->expectException(ValidationException::class);
        $this->invokeProbe('getCategory', [], $this->createQueryRequest('/x', ['category' => str_repeat('a', 101)]));
    }

    public function testProductNotFoundIsConvertedToHttpNotFoundException(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invokeProbe('throwProductNotFound', [], $this->createRequest('GET', '/products/1'));
    }

    public function testOrderNotFoundIsConvertedToHttpNotFoundException(): void
    {
        $this->expectException(HttpNotFoundException::class);
        $this->invokeProbe('throwOrderNotFound', [], $this->createRequest('GET', '/orders/1'));
    }

    /**
     * Invokes the given (possibly protected) Action helper through a real
     * Action::__invoke() call, and returns its result.
     *
     * @param mixed[] $methodArgs
     * @param array<string, string> $routeArgs
     */
    private function invokeProbe(
        string $method,
        array $methodArgs,
        Request $request,
        array $routeArgs = []
    ): mixed {
        $app = $this->getAppInstance();
        $logger = $app->getContainer()->get(LoggerInterface::class);

        $action = new ActionTestProbe($logger, $method, $methodArgs);
        $response = $action($request, (new ResponseFactory())->createResponse(), $routeArgs);

        /** @var array{data: array{result: mixed}} $body */
        $body = json_decode((string) $response->getBody(), true);

        return $body['data']['result'];
    }

    private function createQueryRequest(string $path, array $query): Request
    {
        $uri = (new Uri('', '', 80, $path))->withQuery(http_build_query($query));
        $handle = fopen('php://temp', 'w+');
        $stream = (new StreamFactory())->createStreamFromResource($handle);

        $headers = new Headers();
        $headers->addHeader('HTTP_ACCEPT', 'application/json');

        return new SlimRequest('GET', $uri, $headers, [], [], $stream);
    }
}
