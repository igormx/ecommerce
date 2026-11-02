<?php

declare(strict_types=1);

namespace Tests\Application\Actions;

use App\Application\Actions\Action;
use App\Domain\Order\Exception\OrderNotFoundException;
use App\Domain\Product\Exception\ProductNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * A minimal Action used to exercise protected helpers from tests: it
 * invokes the named method (with the given arguments) and wraps the result
 * in the standard payload, so callers can assert on it like any other
 * Action response.
 */
final class ActionTestProbe extends Action
{
    private string $method;

    /** @var mixed[] */
    private array $methodArgs;

    /**
     * @param mixed[] $methodArgs
     */
    public function __construct(LoggerInterface $logger, string $method, array $methodArgs = [])
    {
        parent::__construct($logger);
        $this->method = $method;
        $this->methodArgs = $methodArgs;
    }

    public function action(): Response
    {
        $result = $this->{$this->method}(...$this->methodArgs);

        return $this->respondWithData(['result' => $result]);
    }

    public function throwProductNotFound(): void
    {
        throw ProductNotFoundException::forId(1);
    }

    public function throwOrderNotFound(): void
    {
        throw OrderNotFoundException::forId(1);
    }
}
