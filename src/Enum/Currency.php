<?php

declare(strict_types=1);

namespace App\Enum;

enum Currency: string
{
    case PLN = 'PLN'; // Polish zloty
    case EUR = 'EUR'; // Euro
    case USD = 'USD'; // US Dollar
    case GBP = 'GBP'; // British Pound
    case JPY = 'JPY'; // Yen
    case CHF = 'CHF'; // Swiss Franc
    case HUF = 'HUF'; // Hungarian Forint

    /**
     * Number of decimal places (ISO 4217 minor units).
     */
    public function scale(): int
    {
        return match ($this) {
            self::JPY => 0,
            default => 2,
        };
    }
}
