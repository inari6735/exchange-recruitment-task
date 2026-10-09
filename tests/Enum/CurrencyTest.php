<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Currency;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    #[DataProvider('scaleProvider')]
    public function testScale(Currency $currency, int $expectedScale): void
    {
        self::assertSame($expectedScale, $currency->scale());
    }

    public static function scaleProvider(): Generator
    {
        yield 'PLN' => [Currency::PLN, 2];
        yield 'EUR' => [Currency::EUR, 2];
        yield 'USD' => [Currency::USD, 2];
        yield 'GBP' => [Currency::GBP, 2];
        yield 'JPY' => [Currency::JPY, 0];
        yield 'CHF' => [Currency::CHF, 2];
        yield 'HUF' => [Currency::HUF, 2];
    }
}
