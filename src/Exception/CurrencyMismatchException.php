<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Currency;
use LogicException;

final class CurrencyMismatchException extends LogicException
{
    public function __construct(Currency $expected, Currency $actual)
    {
        parent::__construct(sprintf('Currency mismatch: expected %s, got %s.', $expected->value, $actual->value));
    }
}
