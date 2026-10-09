<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\AmountInput;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountInputTest extends TestCase
{
    #[DataProvider('valueProvider')]
    public function testToDecimalString(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, AmountInput::toDecimalString($value));
    }

    public static function valueProvider(): Generator
    {
        yield 'string kept' => ['100.50', '100.50'];
        yield 'int' => [100, '100'];
        yield 'float' => [100.5, '100.5'];
        yield 'float with zero fraction' => [100.0, '100'];
        yield 'bool' => [true, null];
        yield 'array' => [['100'], null];
        yield 'null' => [null, null];
    }
}
