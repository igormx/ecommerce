<?php

declare(strict_types=1);

namespace Tests\Application\Actions\Validation;

use App\Application\Actions\Validation\RequestValidator;
use App\Application\Services\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Respect\Validation\Validator as v;

class RequestValidatorTest extends TestCase
{
    private RequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new RequestValidator();
    }

    public function testValidDataIsReturnedUnchanged(): void
    {
        $data = ['sku' => 'SKU-1', 'stock' => 5];

        $result = $this->validator->validate($data, [
            'sku' => v::stringType()->length(1, 32),
            'stock' => v::intType()->between(0, 100),
        ]);

        $this->assertSame($data, $result);
    }

    public function testWrongTypeIsRejected(): void
    {
        try {
            $this->validator->validate(
                ['priceCents' => '12.5'],
                ['priceCents' => v::intType()],
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('priceCents', $e->errors);
        }
    }

    /**
     * @dataProvider nonStrictIntValuesProvider
     */
    public function testStrictIntRejectsStringFloatAndBool(mixed $value): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate(
            ['priceCents' => $value],
            ['priceCents' => v::intType()],
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function nonStrictIntValuesProvider(): array
    {
        return [
            'numeric string' => ['12'],
            'float' => [12.5],
            'bool' => [true],
        ];
    }

    public function testNullIsRejectedForARequiredField(): void
    {
        $this->expectException(ValidationException::class);

        $this->validator->validate(
            ['name' => null],
            ['name' => v::stringType()->notEmpty()],
        );
    }

    public function testMissingKeyIsTreatedAsNullAndFailsARequiredRule(): void
    {
        try {
            $this->validator->validate(
                [],
                ['name' => v::stringType()->notEmpty()],
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name', $e->errors);
        }
    }

    public function testMissingKeyIsAcceptedForAnOptionalField(): void
    {
        $result = $this->validator->validate(
            [],
            ['category' => v::optional(v::stringType()->length(1, 100))],
        );

        $this->assertSame([], $result);
    }

    public function testExplicitNullIsAcceptedForANullableField(): void
    {
        $result = $this->validator->validate(
            ['category' => null],
            ['category' => v::nullable(v::stringType()->length(1, 100))],
        );

        $this->assertSame(['category' => null], $result);
    }

    public function testUnknownKeysAreRejectedByDefault(): void
    {
        try {
            $this->validator->validate(
                ['sku' => 'SKU-1', 'bogus' => 'nope'],
                ['sku' => v::stringType()->length(1, 32)],
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame('unknown field', $e->errors['bogus']);
        }
    }

    public function testUnknownKeysCanBeAllowed(): void
    {
        $data = ['sku' => 'SKU-1', 'extra' => 'kept'];

        $result = $this->validator->validate(
            $data,
            ['sku' => v::stringType()->length(1, 32)],
            false,
        );

        $this->assertSame($data, $result);
    }

    public function testBoundariesAreEnforced(): void
    {
        $this->validator->validate(
            ['stock' => 0],
            ['stock' => v::intType()->between(0, 4_294_967_295)],
        );
        $this->addToAssertionCount(1);

        $this->expectException(ValidationException::class);
        $this->validator->validate(
            ['stock' => -1],
            ['stock' => v::intType()->between(0, 4_294_967_295)],
        );
    }

    public function testMultipleErrorsAreCollectedAtOnce(): void
    {
        try {
            $this->validator->validate(
                ['sku' => '', 'priceCents' => -1, 'bogus' => 'x'],
                [
                    'sku' => v::stringType()->length(1, 32),
                    'priceCents' => v::intType()->between(0, 100),
                ],
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(['sku', 'priceCents', 'bogus'], array_keys($e->errors));
        }
    }

    public function testMessagesAreFlattenedToASingleStringPerField(): void
    {
        try {
            $this->validator->validate(
                ['stock' => 'nope'],
                ['stock' => v::intType()->between(0, 100)],
            );
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertIsString($e->errors['stock']);
            $this->assertNotSame('', $e->errors['stock']);
        }
    }
}
