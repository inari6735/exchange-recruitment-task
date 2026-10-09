<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\ValueObject\Money;
use BcMath\Number;
use RoundingMode;

class SpreadService
{
    private const array LIQUIDITY_SCORE = [
        Currency::USD->value => '1.00',
        Currency::EUR->value => '0.95',
        Currency::GBP->value => '0.85',
        Currency::CHF->value => '0.80',
        Currency::JPY->value => '0.75',
        Currency::PLN->value => '0.55',
        Currency::HUF->value => '0.40',
    ];

    private const string BASE_SPREAD_PERCENT = '0.5';
    private const int PERCENT_SCALE = 10;

    public function calculateSpread(
        Money $price,
        Currency $fromCurrency,
        Currency $toCurrency,
    ): Money {
        if ($fromCurrency === $toCurrency) {
            return Money::zero($price->getCurrency());
        }

        $fromLiquidity = new Number(self::LIQUIDITY_SCORE[$fromCurrency->value]);
        $toLiquidity = self::LIQUIDITY_SCORE[$toCurrency->value];

        $pairLiquidity = $fromLiquidity->add($toLiquidity)->div(2, self::PERCENT_SCALE);

        $spreadPercent = new Number(self::BASE_SPREAD_PERCENT)->div($pairLiquidity, self::PERCENT_SCALE);

        return $price->percentage($spreadPercent, RoundingMode::HalfAwayFromZero);
    }
}
