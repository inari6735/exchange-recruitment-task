<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Exception\InvalidMoneyAmountException;
use App\Service\TransactionLimits;
use App\Tests\Support\TransactionLimitsFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TransactionLimitsTest extends TestCase
{
    public function testReturnsLimitsPerCurrency(): void
    {
        $limits = TransactionLimitsFactory::default();

        self::assertSame('2500.00', $limits->depositLimit(Currency::EUR)->toString());
        self::assertSame(Currency::EUR, $limits->depositLimit(Currency::EUR)->getCurrency());
        self::assertSame('10000.00', $limits->depositLimit(Currency::PLN)->toString());
        self::assertSame('450000', $limits->depositLimit(Currency::JPY)->toString());
        self::assertSame('15000.00', $limits->antiFraudThreshold(Currency::PLN)->toString());
        self::assertSame('650000', $limits->antiFraudThreshold(Currency::JPY)->toString());
        self::assertSame(Currency::HUF, $limits->antiFraudThreshold(Currency::HUF)->getCurrency());
    }

    public function testRejectsMissingCurrency(): void
    {
        $depositLimits = TransactionLimitsFactory::DEPOSIT_LIMITS;
        unset($depositLimits['HUF']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing deposit limit for HUF.');

        new TransactionLimits(TransactionLimitsFactory::ANTI_FRAUD_THRESHOLDS, $depositLimits);
    }

    public function testRejectsNonPositiveValue(): void
    {
        $thresholds = ['PLN' => '0'] + TransactionLimitsFactory::ANTI_FRAUD_THRESHOLDS;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anti-fraud threshold for PLN must be positive.');

        new TransactionLimits($thresholds, TransactionLimitsFactory::DEPOSIT_LIMITS);
    }

    public function testRejectsValueTooPreciseForCurrency(): void
    {
        $depositLimits = ['JPY' => '100.5'] + TransactionLimitsFactory::DEPOSIT_LIMITS;

        $this->expectException(InvalidMoneyAmountException::class);

        new TransactionLimits(TransactionLimitsFactory::ANTI_FRAUD_THRESHOLDS, $depositLimits);
    }
}
