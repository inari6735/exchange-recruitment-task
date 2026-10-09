<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\Currency;
use App\Service\TransactionLimits;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransactionLimitsWiringTest extends KernelTestCase
{
    public function testLimitsComeFromServiceParameters(): void
    {
        self::bootKernel();
        $limits = self::getContainer()->get(TransactionLimits::class);

        self::assertSame('2500.00', $limits->depositLimit(Currency::EUR)->toString());
        self::assertSame('10000.00', $limits->depositLimit(Currency::PLN)->toString());
        self::assertSame('650000', $limits->antiFraudThreshold(Currency::JPY)->toString());
        self::assertSame('1250000.00', $limits->antiFraudThreshold(Currency::HUF)->toString());
    }
}
