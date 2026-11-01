<?php

declare(strict_types=1);

namespace App\Domain\Order\Exception;

use App\Domain\DomainException\DomainRecordNotFoundException;

final class OrderNotFoundException extends DomainRecordNotFoundException
{
    public static function forId(int $id): self
    {
        return new self(sprintf('Order with id %d not found.', $id));
    }
}
