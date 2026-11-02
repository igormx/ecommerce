<?php

declare(strict_types=1);

namespace App\Application\Actions\Product;

use App\Application\Actions\Action;
use App\Application\Services\ProductService;
use App\Domain\Product\Exception\ProductNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ViewProductAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly ProductService $products)
    {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = $this->getIntArg('id');
        $product = $this->products->get($id);

        // Deactivated (soft-deleted) products are not publicly visible.
        if (!$product->active) {
            throw ProductNotFoundException::forId($id);
        }

        return $this->respondWithData(ProductResource::toArray($product));
    }
}
