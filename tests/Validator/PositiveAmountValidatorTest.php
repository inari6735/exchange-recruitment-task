<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\PositiveAmount;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PositiveAmountValidatorTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidator();
    }

    #[DataProvider('validProvider')]
    public function testAcceptsPositiveAmounts(mixed $value): void
    {
        self::assertCount(0, $this->validator->validate($value, new PositiveAmount()));
    }

    public static function validProvider(): Generator
    {
        yield 'decimal string' => ['100.50'];
        yield 'integer string' => ['100'];
        yield 'small' => ['0.01'];
        yield 'json int' => [500];
        yield 'json float' => [100.5];
        yield 'null is left to NotNull' => [null];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsEverythingElse(mixed $value): void
    {
        $violations = $this->validator->validate($value, new PositiveAmount());

        self::assertCount(1, $violations);
        self::assertSame('Amount must be a positive number.', $violations[0]->getMessage());
    }

    public static function invalidProvider(): Generator
    {
        yield 'negative' => ['-50'];
        yield 'zero string' => ['0.00'];
        yield 'zero int' => [0];
        yield 'negative zero float' => [-0.0];
        yield 'letters' => ['abc'];
        yield 'exponent string' => ['1e3'];
        yield 'exponent float' => [1e25];
        yield 'leading space' => [' 100'];
        yield 'trailing newline' => ["100\n"];
        yield 'boolean' => [true];
        yield 'array' => [['100']];
    }
}
