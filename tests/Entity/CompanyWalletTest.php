<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CompanyWallet;
use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class CompanyWalletTest extends TestCase
{
    public function testCreateInitialValues(): void
    {
        $wallet = CompanyWallet::create(Currency::EUR);

        $this->assertNull($wallet->getId());
        $this->assertSame(Currency::EUR, $wallet->getCurrency());
        $this->assertSame('0.00', $wallet->getBalance()->toString());
    }

    public function testCreateSetsTimestamps(): void
    {
        $before = new DateTimeImmutable();
        $wallet = CompanyWallet::create(Currency::PLN);
        $after = new DateTimeImmutable();

        $this->assertGreaterThanOrEqual($before, $wallet->getCreatedAt());
        $this->assertLessThanOrEqual($after, $wallet->getCreatedAt());
        $this->assertGreaterThanOrEqual($before, $wallet->getUpdatedAt());
        $this->assertLessThanOrEqual($after, $wallet->getUpdatedAt());
    }

    public function testCreateSetsEqualCreatedAtAndUpdatedAt(): void
    {
        $wallet = CompanyWallet::create(Currency::USD);

        $this->assertSame($wallet->getCreatedAt(), $wallet->getUpdatedAt());
    }

    public function testSetBalance(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);
        $wallet->setBalance(Money::of('250.75', Currency::PLN));

        $this->assertSame('250.75', $wallet->getBalance()->toString());
    }

    public function testSetBalanceToZero(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);
        $wallet->setBalance(Money::of('100.00', Currency::PLN));
        $wallet->setBalance(Money::zero(Currency::PLN));

        $this->assertSame('0.00', $wallet->getBalance()->toString());
    }

    public function testSetBalanceRejectsDifferentCurrency(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);

        $this->expectException(CurrencyMismatchException::class);

        $wallet->setBalance(Money::of('1.00', Currency::EUR));
    }

    public function testGetCurrency(): void
    {
        foreach (Currency::cases() as $currency) {
            $wallet = CompanyWallet::create($currency);
            $this->assertSame($currency, $wallet->getCurrency());
        }
    }
}
