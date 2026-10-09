<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\TransactionLimits;

final class TransactionLimitsFactory
{
    public const array ANTI_FRAUD_THRESHOLDS = [
        'PLN' => '15000',
        'EUR' => '3500',
        'USD' => '4000',
        'GBP' => '3000',
        'CHF' => '3200',
        'JPY' => '650000',
        'HUF' => '1250000',
    ];

    public const array DEPOSIT_LIMITS = [
        'PLN' => '10000',
        'EUR' => '2500',
        'USD' => '2500',
        'GBP' => '2000',
        'CHF' => '2000',
        'JPY' => '450000',
        'HUF' => '850000',
    ];

    public static function default(): TransactionLimits
    {
        return new TransactionLimits(self::ANTI_FRAUD_THRESHOLDS, self::DEPOSIT_LIMITS);
    }
}
