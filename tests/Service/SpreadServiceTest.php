<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Service\SpreadService;
use App\ValueObject\Money;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpreadServiceTest extends TestCase
{
    private SpreadService $spreadService;

    protected function setUp(): void
    {
        $this->spreadService = new SpreadService();
    }

    #[DataProvider('calculateSpreadDataProvider')]
    public function testCalculateSpread(
        string $price,
        Currency $fromCurrency,
        Currency $toCurrency,
        string $expectedResult,
    ): void {
        $result = $this->spreadService->calculateSpread(Money::of($price, $toCurrency), $fromCurrency, $toCurrency);

        self::assertSame($expectedResult, $result->toString());
        self::assertSame($toCurrency, $result->getCurrency());
    }

    public static function calculateSpreadDataProvider(): Generator
    {
        yield 'USD to USD (same currency, no conversion)' => [
            'price' => '100.00',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::USD,
            'expectedResult' => '0.00',
        ];
        yield 'PLN to PLN (same currency, no conversion)' => [
            'price' => '100.00',
            'fromCurrency' => Currency::PLN,
            'toCurrency' => Currency::PLN,
            'expectedResult' => '0.00',
        ];
        yield 'HUF to HUF (same currency, no conversion)' => [
            'price' => '100.00',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::HUF,
            'expectedResult' => '0.00',
        ];
        yield 'USD to EUR' => [
            'price' => '100.00',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '0.51',
        ];
        yield 'GBP to CHF' => [
            'price' => '100.00',
            'fromCurrency' => Currency::GBP,
            'toCurrency' => Currency::CHF,
            'expectedResult' => '0.61',
        ];
        yield 'HUF to JPY (rounded to whole yen)' => [
            'price' => '100',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::JPY,
            'expectedResult' => '1',
        ];
        yield 'HUF to JPY with higher price' => [
            'price' => '10000',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::JPY,
            'expectedResult' => '87',
        ];
        yield 'HUF to PLN' => [
            'price' => '50.00',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::PLN,
            'expectedResult' => '0.53',
        ];
        yield 'USD to EUR with higher price' => [
            'price' => '200.00',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '1.03',
        ];
        yield 'PLN to HUF (transfer reference case)' => [
            'price' => '8474.58',
            'fromCurrency' => Currency::PLN,
            'toCurrency' => Currency::HUF,
            'expectedResult' => '89.21',
        ];
    }
}
