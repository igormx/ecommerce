<?php

declare(strict_types=1);

namespace App\Application\Actions\Product;

use App\Application\Actions\Action;
use App\Application\Services\ProductService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CreateProductAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly ProductService $products)
    {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $input = ProductInput::fromBody($this->getJsonBody());

        $product = $this->products->create(
            $input->sku,
            $input->name,
            $input->description,
            $input->category,
            $input->priceCents,
            $input->stock,
            $input->weightKg,
        );

        return $this->respondWithData(ProductResource::toArray($product), 201);
    }
}
