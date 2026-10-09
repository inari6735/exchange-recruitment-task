<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\Enum\Currency;
use App\ValueObject\ExchangeRate;
use BcMath\Number;
use PHPUnit\Framework\TestCase;

class ExchangeRateTest extends TestCase
{
    public function testOfRoundsToSixDecimalPlaces(): void
    {
        $rate = ExchangeRate::of(Currency::PLN, Currency::EUR, '0.2359102597');

        self::assertSame('0.235910', $rate->toString());
        self::assertSame(Currency::PLN, $rate->getFrom());
        self::assertSame(Currency::EUR, $rate->getTo());
    }

    public function testOfPadsToSixDecimalPlaces(): void
    {
        self::assertSame('4.238900', ExchangeRate::of(Currency::EUR, Currency::PLN, '4.2389')->toString());
        self::assertSame('1.000000', ExchangeRate::of(Currency::PLN, Currency::PLN, '1')->toString());
    }

    public function testOfRoundsHalfAwayFromZero(): void
    {
        self::assertSame('0.000001', ExchangeRate::of(Currency::PLN, Currency::EUR, '0.0000005')->toString());
    }

    public function testOfAcceptsNumber(): void
    {
        $rate = ExchangeRate::of(Currency::USD, Currency::EUR, new Number('0.86029394418'));

        self::assertSame('0.860294', $rate->toString());
        self::assertSame('0.860294', $rate->getRate()->value);
    }
}
