<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\Exception\InvalidMoneyAmountException;
use BcMath\Number;
use RoundingMode;

final readonly class Money
{
    private const string FORMAT = '/^-?\d+(\.\d+)?$/D';
    private const int INTERMEDIATE_SCALE = 20;

    /**
     * @param Number $amount always rounded to $currency->scale()
     */
    private function __construct(
        private Number $amount,
        private Currency $currency,
    ) {
    }

    /**
     * @throws InvalidMoneyAmountException when the format is invalid or the amount is more precise than the currency allows
     */
    public static function of(string $amount, Currency $currency): self
    {
        if (!self::isValidFormat($amount)) {
            throw InvalidMoneyAmountException::invalidFormat($amount);
        }

        $fraction = explode('.', $amount)[1] ?? '';
        if ('' !== rtrim(substr($fraction, $currency->scale()), '0')) {
            throw InvalidMoneyAmountException::tooManyDecimalPlaces($currency);
        }

        return new self(new Number($amount)->round($currency->scale()), $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(new Number(0)->round($currency->scale()), $currency);
    }

    public static function isValidFormat(string $amount): bool
    {
        return 1 === preg_match(self::FORMAT, $amount);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other->currency);

        return new self($this->amount->add($other->amount), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other->currency);

        return new self($this->amount->sub($other->amount), $this->currency);
    }

    public function convertTo(Currency $target, ExchangeRate $rate, RoundingMode $mode): self
    {
        $this->assertSameCurrency($rate->getFrom());

        if ($rate->getTo() !== $target) {
            throw new CurrencyMismatchException($target, $rate->getTo());
        }

        return new self($this->amount->mul($rate->getRate())->round($target->scale(), $mode), $target);
    }

    /**
     * @param Number $percent e.g. 1.5 for 1.5%
     */
    public function percentage(Number $percent, RoundingMode $mode): self
    {
        $result = $this->amount
            ->mul($percent)
            ->div(100, self::INTERMEDIATE_SCALE)
            ->round($this->currency->scale(), $mode);

        return new self($result, $this->currency);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other->currency);

        return $this->amount->compare($other->amount) > 0;
    }

    public function equals(self $other): bool
    {
        $this->assertSameCurrency($other->currency);

        return 0 === $this->amount->compare($other->amount);
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function toString(): string
    {
        return $this->amount->value;
    }

    private function assertSameCurrency(Currency $currency): void
    {
        if ($currency !== $this->currency) {
            throw new CurrencyMismatchException($this->currency, $currency);
        }
    }
}
