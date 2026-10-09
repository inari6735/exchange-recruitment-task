<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\Currency;
use BcMath\Number;

final readonly class ExchangeRate
{
    public const int SCALE = 6;

    private function __construct(
        private Currency $from,
        private Currency $to,
        private Number $rate,
    ) {
    }

    /**
     * Amount of $to currency per one unit of $from currency, rounded to SCALE decimal places.
     */
    public static function of(Currency $from, Currency $to, Number|string $rate): self
    {
        $number = $rate instanceof Number ? $rate : new Number($rate);

        return new self($from, $to, $number->round(self::SCALE));
    }

    public function getFrom(): Currency
    {
        return $this->from;
    }

    public function getTo(): Currency
    {
        return $this->to;
    }

    public function getRate(): Number
    {
        return $this->rate;
    }

    public function toString(): string
    {
        return $this->rate->value;
    }
}
