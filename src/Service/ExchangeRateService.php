<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\ValueObject\ExchangeRate;
use BcMath\Number;

class ExchangeRateService
{
    private const int DIVISION_SCALE = 10;

    /**
     * Price of one unit of $currency in PLN.
     */
    public function getExchangeRate(Currency $currency): Number
    {
        return new Number(match ($currency) {
            Currency::PLN => '1',
            Currency::EUR => '4.2389',
            Currency::USD => '3.6467',
            Currency::GBP => '4.881',
            Currency::JPY => '0.0229',
            Currency::CHF => '4.6347',
            Currency::HUF => '0.0118',
        });
    }

    public function getExchangeRateBetween(Currency $from, Currency $to): ExchangeRate
    {
        $rate = $this->getExchangeRate($from)->div($this->getExchangeRate($to), self::DIVISION_SCALE);

        return ExchangeRate::of($from, $to, $rate);
    }
}
