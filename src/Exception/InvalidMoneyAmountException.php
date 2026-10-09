<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Currency;
use RuntimeException;

final class InvalidMoneyAmountException extends RuntimeException
{
    public static function invalidFormat(string $amount): self
    {
        return new self(sprintf('Invalid money amount "%s".', $amount));
    }

    public static function tooManyDecimalPlaces(Currency $currency): self
    {
        return new self(sprintf('Amount has too many decimal places for %s.', $currency->value));
    }
}
