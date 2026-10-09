<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\Exception\InvalidMoneyAmountException;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use BcMath\Number;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RoundingMode;

class MoneyTest extends TestCase
{
    #[DataProvider('validAmountProvider')]
    public function testOfFormatsToCurrencyScale(string $amount, Currency $currency, string $expected): void
    {
        $money = Money::of($amount, $currency);

        self::assertSame($expected, $money->toString());
        self::assertSame($currency, $money->getCurrency());
    }

    public static function validAmountProvider(): Generator
    {
        yield 'integer PLN' => ['100', Currency::PLN, '100.00'];
        yield 'one decimal PLN' => ['12.5', Currency::PLN, '12.50'];
        yield 'two decimals PLN' => ['12.34', Currency::PLN, '12.34'];
        yield 'negative PLN' => ['-5.10', Currency::PLN, '-5.10'];
        yield 'integer JPY' => ['1250', Currency::JPY, '1250'];
    }

    public function testOfAcceptsTrailingZerosBeyondScale(): void
    {
        self::assertSame('12.50', Money::of('12.5000', Currency::PLN)->toString());
        self::assertSame('4333', Money::of('4333.0000', Currency::JPY)->toString());
    }

    public function testOfNormalizesNegativeZeroAndLeadingZeros(): void
    {
        self::assertSame('0.00', Money::of('-0.00', Currency::PLN)->toString());
        self::assertSame('7.50', Money::of('007.5', Currency::PLN)->toString());
    }

    #[DataProvider('invalidFormatProvider')]
    public function testOfRejectsInvalidFormat(string $amount): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::of($amount, Currency::PLN);
    }

    public static function invalidFormatProvider(): Generator
    {
        yield 'empty' => [''];
        yield 'exponent' => ['1e5'];
        yield 'leading space' => [' 100'];
        yield 'trailing newline' => ["100\n"];
        yield 'comma' => ['10,50'];
        yield 'plus sign' => ['+10'];
        yield 'dot only' => ['.5'];
        yield 'trailing dot' => ['5.'];
        yield 'letters' => ['abc'];
    }

    public function testOfRejectsTooManyDecimalPlaces(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for PLN.');

        Money::of('12.505', Currency::PLN);
    }

    public function testOfRejectsDecimalsForJpy(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for JPY.');

        Money::of('100.5', Currency::JPY);
    }

    public function testZero(): void
    {
        self::assertSame('0.00', Money::zero(Currency::EUR)->toString());
        self::assertSame('0', Money::zero(Currency::JPY)->toString());
    }

    public function testIsValidFormat(): void
    {
        self::assertTrue(Money::isValidFormat('100.50'));
        self::assertTrue(Money::isValidFormat('-1'));
        self::assertFalse(Money::isValidFormat('1e3'));
        self::assertFalse(Money::isValidFormat("100\n"));
    }

    public function testAdd(): void
    {
        $sum = Money::of('10.25', Currency::PLN)->add(Money::of('0.75', Currency::PLN));

        self::assertSame('11.00', $sum->toString());
    }

    public function testSubtract(): void
    {
        $difference = Money::of('10.00', Currency::PLN)->subtract(Money::of('10.01', Currency::PLN));

        self::assertSame('-0.01', $difference->toString());
    }

    public function testAddIsExactWhereFloatIsNot(): void
    {
        $sum = Money::of('0.10', Currency::PLN)->add(Money::of('0.20', Currency::PLN));

        self::assertTrue($sum->equals(Money::of('0.30', Currency::PLN)));
    }

    public function testAddRejectsDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);
        $this->expectExceptionMessage('Currency mismatch: expected PLN, got EUR.');

        Money::of('1.00', Currency::PLN)->add(Money::of('1.00', Currency::EUR));
    }

    public function testSubtractRejectsDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::PLN)->subtract(Money::of('1.00', Currency::EUR));
    }

    public function testConvertToRoundsToTargetScale(): void
    {
        $rate = ExchangeRate::of(Currency::PLN, Currency::HUF, '84.745763');

        $converted = Money::of('100.00', Currency::PLN)->convertTo(Currency::HUF, $rate, RoundingMode::HalfAwayFromZero);

        self::assertSame('8474.58', $converted->toString());
        self::assertSame(Currency::HUF, $converted->getCurrency());
    }

    public function testConvertToJpyRoundsToWholeUnits(): void
    {
        $rate = ExchangeRate::of(Currency::PLN, Currency::JPY, '43.668122');

        $converted = Money::of('100.00', Currency::PLN)->convertTo(Currency::JPY, $rate, RoundingMode::HalfAwayFromZero);

        self::assertSame('4367', $converted->toString());
    }

    public function testConvertToUsesRoundingModeOnHalf(): void
    {
        $rate = ExchangeRate::of(Currency::PLN, Currency::EUR, '0.5');

        $money = Money::of('0.05', Currency::PLN); // 0.025 EUR

        self::assertSame('0.03', $money->convertTo(Currency::EUR, $rate, RoundingMode::HalfAwayFromZero)->toString());
        self::assertSame('0.02', $money->convertTo(Currency::EUR, $rate, RoundingMode::HalfEven)->toString());
    }

    public function testConvertToRejectsRateFromOtherCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::USD)->convertTo(
            Currency::EUR,
            ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            RoundingMode::HalfAwayFromZero,
        );
    }

    public function testConvertToRejectsRateToOtherCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::PLN)->convertTo(
            Currency::USD,
            ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            RoundingMode::HalfAwayFromZero,
        );
    }

    public function testPercentage(): void
    {
        $percent = new Number('1.0526315789');

        $result = Money::of('8474.58', Currency::HUF)->percentage($percent, RoundingMode::HalfAwayFromZero);

        self::assertSame('89.21', $result->toString());
        self::assertSame(Currency::HUF, $result->getCurrency());
    }

    public function testPercentageForJpy(): void
    {
        $result = Money::of('4367', Currency::JPY)->percentage(new Number('0.7692307692'), RoundingMode::HalfAwayFromZero);

        self::assertSame('34', $result->toString());
    }

    public function testIsGreaterThan(): void
    {
        $threshold = Money::of('15000', Currency::HUF);

        self::assertTrue(Money::of('15000.01', Currency::HUF)->isGreaterThan($threshold));
        self::assertFalse(Money::of('15000.00', Currency::HUF)->isGreaterThan($threshold));
        self::assertFalse(Money::of('14999.99', Currency::HUF)->isGreaterThan($threshold));
    }

    public function testIsGreaterThanRejectsDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::PLN)->isGreaterThan(Money::of('1.00', Currency::EUR));
    }

    public function testEquals(): void
    {
        self::assertTrue(Money::of('1.5', Currency::PLN)->equals(Money::of('1.50', Currency::PLN)));
        self::assertFalse(Money::of('1.50', Currency::PLN)->equals(Money::of('1.51', Currency::PLN)));
    }

    public function testEqualsRejectsDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::PLN)->equals(Money::of('1.00', Currency::EUR));
    }
}
