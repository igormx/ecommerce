<?php

declare(strict_types=1);

namespace App\Application\Actions\Product;

use App\Application\Actions\Action;
use App\Application\Services\ProductService;
use App\Domain\Product\Exception\ProductNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

/**
 * Full replace of a product's editable fields.
 */
class UpdateProductAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly ProductService $products)
    {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $id = $this->getIntArg('id');
        $input = ProductInput::fromBody($this->getJsonBody());

        // Deactivated (soft-deleted) products cannot be edited; same 404 as ViewProductAction.
        if (!$this->products->get($id)->active) {
            throw ProductNotFoundException::forId($id);
        }

        $product = $this->products->update(
            $id,
            $input->sku,
            $input->name,
            $input->description,
            $input->category,
            $input->priceCents,
            $input->stock,
            $input->weightKg,
        );

        return $this->respondWithData(ProductResource::toArray($product));
    }
}
