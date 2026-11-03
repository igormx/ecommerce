<?php

declare(strict_types=1);

namespace App\Application\Actions\Product;

use App\Application\Actions\Action;
use App\Application\Services\ProductService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class SearchProductsAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly ProductService $products)
    {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $query = $this->getSearchTerm();
        $category = $this->getCategory();
        $page = $this->getPage();
        $size = $this->getSize();

        $products = $this->products->search($query, $category, $page, $size);
        $total = $this->products->countSearch($query, $category);

        return $this->respondWithPage(ProductResource::collection($products), $page, $size, $total);
    }
}
