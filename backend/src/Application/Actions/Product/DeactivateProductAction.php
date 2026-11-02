<?php

declare(strict_types=1);

namespace App\Application\Actions\Product;

use App\Application\Actions\Action;
use App\Application\Services\ProductService;
use App\Domain\Product\Exception\ProductNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class DeactivateProductAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly ProductService $products)
    {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = $this->getIntArg('id');

        // An already-deactivated product is gone as far as the API is concerned (404, as in View).
        if (!$this->products->get($id)->active) {
            throw ProductNotFoundException::forId($id);
        }

        $this->products->deactivate($id);

        return $this->response->withStatus(204);
    }
}
