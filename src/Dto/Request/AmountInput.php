<?php

declare(strict_types=1);

namespace App\Dto\Request;

/**
 * Amounts arrive either as decimal strings ("100.50") or JSON numbers (100.5).
 */
final class AmountInput
{
    public static function toDecimalString(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return \is_string($value) ? $value : null;
    }
}
