<?php

declare(strict_types=1);

namespace App\Application\Actions\Purchase;

use App\Application\Actions\Action;
use App\Application\Actions\Order\OrderResource;
use App\Application\Services\PurchaseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class CreatePurchaseAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly PurchaseService $purchases)
    {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $input = PurchaseInput::fromBody($this->getJsonBody());

        // A declined payment is not an error: the FAILED order is returned with 201.
        $order = $this->purchases->purchase($input->lines);

        return $this->respondWithData(OrderResource::toArray($order), 201);
    }
}
