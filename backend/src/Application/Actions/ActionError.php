<?php

declare(strict_types=1);

namespace App\Application\Actions;

use JsonSerializable;

class ActionError implements JsonSerializable
{
    public const BAD_REQUEST = 'BAD_REQUEST';
    public const CONFLICT = 'CONFLICT';
    public const INSUFFICIENT_PRIVILEGES = 'INSUFFICIENT_PRIVILEGES';
    public const INSUFFICIENT_STOCK = 'INSUFFICIENT_STOCK';
    public const NOT_ALLOWED = 'NOT_ALLOWED';
    public const NOT_IMPLEMENTED = 'NOT_IMPLEMENTED';
    public const RESOURCE_NOT_FOUND = 'RESOURCE_NOT_FOUND';
    public const SERVER_ERROR = 'SERVER_ERROR';
    public const SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    public const UNAUTHENTICATED = 'UNAUTHENTICATED';
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';
    public const VERIFICATION_ERROR = 'VERIFICATION_ERROR';

    private string $type;

    private ?string $description;

    /**
     * @var array<string, string>|null field => message, set only for VALIDATION_ERROR
     */
    private ?array $fields;

    /**
     * @param array<string, string>|null $fields field => message
     */
    public function __construct(string $type, ?string $description = null, ?array $fields = null)
    {
        $this->type = $type;
        $this->description = $description;
        $this->fields = $fields;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description = null): self
    {
        $this->description = $description;
        return $this;
    }

    /**
     * @return array<string, string>|null
     */
    public function getFields(): ?array
    {
        return $this->fields;
    }

    /**
     * @param array<string, string>|null $fields
     */
    public function setFields(?array $fields): self
    {
        $this->fields = $fields;
        return $this;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize(): array
    {
        $serialized = [
            'type' => $this->type,
            'description' => $this->description,
        ];

        if ($this->fields !== null) {
            $serialized['fields'] = $this->fields;
        }

        return $serialized;
    }
}
