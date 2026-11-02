<?php

declare(strict_types=1);

namespace App\Application\Actions\Validation;

use App\Application\Services\Exception\ValidationException;
use Respect\Validation\Exceptions\NestedValidationException;
use Respect\Validation\Validatable;

/**
 * Thin adapter over respect/validation: validates a flat array of fields
 * against a map of rules, collecting every failure instead of stopping at
 * the first one.
 */
final class RequestValidator
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, Validatable> $rules field => rule. Whether a
     *     field is required, optional or nullable is expressed on the rule
     *     itself (e.g. `v::optional(...)` for a field that may be absent or
     *     blank, `v::nullable(...)` for one that may be explicitly null),
     *     not by this method.
     * @return array<string, mixed> $data, once every rule has passed
     * @throws ValidationException field => message, for every failing rule
     *     and every unknown key
     */
    public function validate(array $data, array $rules, bool $rejectUnknown = true): array
    {
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = array_key_exists($field, $data) ? $data[$field] : null;

            try {
                $rule->assert($value);
            } catch (NestedValidationException $e) {
                $errors[$field] = $this->flatten($e->getMessages());
            }
        }

        if ($rejectUnknown) {
            foreach (array_keys($data) as $field) {
                if (!array_key_exists($field, $rules)) {
                    $errors[(string) $field] = 'unknown field';
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    /**
     * respect/validation reports a failure as a tree of messages (one per
     * sub-rule). Flatten it to a single string so each field maps to one
     * message.
     *
     * @param string[]|array<array-key, string[]> $messages
     */
    private function flatten(array $messages): string
    {
        $flat = [];
        array_walk_recursive($messages, function (string $message) use (&$flat): void {
            $flat[] = $message;
        });

        return implode(' ', $flat);
    }
}
