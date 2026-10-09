<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Service\ExchangeRateService;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    private ExchangeRateService $exchangeRateService;

    protected function setUp(): void
    {
        $this->exchangeRateService = new ExchangeRateService();
    }

    #[DataProvider('exchangeRateDataProvider')]
    public function testGetExchangeRate(
        Currency $currency,
        string $expectedResult,
    ): void {
        $result = $this->exchangeRateService->getExchangeRate($currency);

        self::assertSame($expectedResult, $result->value);
    }

    #[DataProvider('exchangeRateBetweenDataProvider')]
    public function testGetExchangeRateBetween(
        Currency $from,
        Currency $to,
        string $expectedResult,
    ): void {
        $result = $this->exchangeRateService->getExchangeRateBetween($from, $to);

        self::assertSame($expectedResult, $result->toString());
        self::assertSame($from, $result->getFrom());
        self::assertSame($to, $result->getTo());
    }

    public static function exchangeRateDataProvider(): Generator
    {
        yield 'PLN' => ['currency' => Currency::PLN, 'expectedResult' => '1'];
        yield 'EUR' => ['currency' => Currency::EUR, 'expectedResult' => '4.2389'];
        yield 'USD' => ['currency' => Currency::USD, 'expectedResult' => '3.6467'];
        yield 'GBP' => ['currency' => Currency::GBP, 'expectedResult' => '4.881'];
        yield 'JPY' => ['currency' => Currency::JPY, 'expectedResult' => '0.0229'];
        yield 'CHF' => ['currency' => Currency::CHF, 'expectedResult' => '4.6347'];
        yield 'HUF' => ['currency' => Currency::HUF, 'expectedResult' => '0.0118'];
    }

    public static function exchangeRateBetweenDataProvider(): Generator
    {
        yield 'PLN to PLN' => [
            'from' => Currency::PLN,
            'to' => Currency::PLN,
            'expectedResult' => '1.000000',
        ];
        yield 'EUR to PLN' => [
            'from' => Currency::EUR,
            'to' => Currency::PLN,
            'expectedResult' => '4.238900',
        ];
        yield 'PLN to EUR' => [
            'from' => Currency::PLN,
            'to' => Currency::EUR,
            'expectedResult' => '0.235910',
        ];
        yield 'USD to EUR' => [
            'from' => Currency::USD,
            'to' => Currency::EUR,
            'expectedResult' => '0.860294',
        ];
        yield 'GBP to CHF' => [
            'from' => Currency::GBP,
            'to' => Currency::CHF,
            'expectedResult' => '1.053143',
        ];
        yield 'PLN to HUF' => [
            'from' => Currency::PLN,
            'to' => Currency::HUF,
            'expectedResult' => '84.745763',
        ];
    }
}
