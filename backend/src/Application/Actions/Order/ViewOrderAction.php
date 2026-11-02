<?php

declare(strict_types=1);

namespace App\Application\Actions\Order;

use App\Application\Actions\Action;
use App\Domain\Order\OrderRepositoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;

class ViewOrderAction extends Action
{
    public function __construct(LoggerInterface $logger, private readonly OrderRepositoryInterface $orders)
    {
        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $order = $this->orders->findById($this->getIntArg('id'));

        return $this->respondWithData(OrderResource::toArray($order));
    }
}
