<?php

declare(strict_types=1);

namespace App\Application\Actions;

use JsonSerializable;

class ActionPayload implements JsonSerializable
{
    private int $statusCode;

    /**
     * @var array|object|null
     */
    private $data;

    private ?ActionError $error;

    /**
     * @var array|null extra response metadata, e.g. pagination info
     */
    private ?array $meta;

    /**
     * @param array|null $meta
     */
    public function __construct(
        int $statusCode = 200,
        $data = null,
        ?ActionError $error = null,
        ?array $meta = null
    ) {
        $this->statusCode = $statusCode;
        $this->data = $data;
        $this->error = $error;
        $this->meta = $meta;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array|null|object
     */
    public function getData()
    {
        return $this->data;
    }

    public function getError(): ?ActionError
    {
        return $this->error;
    }

    /**
     * @return array|null
     */
    public function getMeta()
    {
        return $this->meta;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        $payload = [
            'statusCode' => $this->statusCode,
        ];

        if ($this->data !== null) {
            $payload['data'] = $this->data;
        } elseif ($this->error !== null) {
            $payload['error'] = $this->error;
        }

        if ($this->meta !== null) {
            $payload['meta'] = $this->meta;
        }

        return $payload;
    }
}
