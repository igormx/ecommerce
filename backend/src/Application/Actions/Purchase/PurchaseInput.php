<?php

declare(strict_types=1);

namespace App\Application\Actions\Purchase;

use App\Application\Actions\Validation\RequestValidator;
use App\Application\Services\Exception\ValidationException;
use App\Application\Services\PurchaseService;
use Respect\Validation\Validatable;
use Respect\Validation\Validator as v;

final class PurchaseInput
{
    /**
     * @param array<int, int> $lines productId => quantity
     */
    private function __construct(public readonly array $lines)
    {
    }

    /**
     * @return array<string, Validatable>
     */
    public static function rules(): array
    {
        return [
            'items' => v::arrayType()->length(1, PurchaseService::MAX_LINES),
        ];
    }

    /**
     * @return array<string, Validatable>
     */
    public static function lineRules(): array
    {
        return [
            'productId' => v::intType()->min(1),
            'quantity' => v::intType()->between(1, PurchaseService::MAX_QUANTITY),
        ];
    }

    /**
     * @param array<array-key, mixed> $body
     * @throws ValidationException
     */
    public static function fromBody(array $body): self
    {
        $validator = new RequestValidator();
        $data = $validator->validate($body, self::rules());

        /** @var array<array-key, mixed> $items */
        $items = $data['items'];
        if (!array_is_list($items)) {
            throw new ValidationException(['items' => 'must be a list']);
        }

        $errors = [];
        $lines = [];
        foreach ($items as $index => $item) {
            if (!is_array($item) || ($item !== [] && array_is_list($item))) {
                $errors["items.{$index}"] = 'must be an object';
                continue;
            }

            try {
                $line = $validator->validate($item, self::lineRules());
            } catch (ValidationException $e) {
                foreach ($e->errors as $field => $message) {
                    $errors["items.{$index}.{$field}"] = $message;
                }
                continue;
            }

            if (isset($lines[$line['productId']])) {
                $errors['items'] = "duplicate productId {$line['productId']}";
                continue;
            }
            $lines[$line['productId']] = $line['quantity'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return new self($lines);
    }
}
