<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\CompanyWallet;
use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;
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

    public function testCredit(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);
        $wallet->credit(Money::of('250.75', Currency::PLN));
        $wallet->credit(Money::of('0.25', Currency::PLN));

        $this->assertSame('251.00', $wallet->getBalance()->toString());
    }

    public function testCreditRejectsNonPositiveAmount(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive.');

        $wallet->credit(Money::zero(Currency::PLN));
    }

    public function testCreditRejectsDifferentCurrency(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);

        $this->expectException(CurrencyMismatchException::class);

        $wallet->credit(Money::of('1.00', Currency::EUR));
    }

    public function testGetCurrency(): void
    {
        foreach (Currency::cases() as $currency) {
            $wallet = CompanyWallet::create($currency);
            $this->assertSame($currency, $wallet->getCurrency());
        }
    }
}
