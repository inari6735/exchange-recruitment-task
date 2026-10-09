<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\Exception\InsufficientFundsException;
use App\Exception\WalletBlockedException;
use App\Tests\Support\WalletFixture;
use App\ValueObject\Money;
use DateTimeImmutable;
use Generator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WalletTest extends TestCase
{
    public function testGetters(): void
    {
        $wallet = Wallet::create(userId: 5, currency: Currency::EUR);

        $this->assertNull($wallet->getId());
        $this->assertSame(5, $wallet->getUserId());
        $this->assertSame(Currency::EUR, $wallet->getCurrency());
        $this->assertSame('0.00', $wallet->getBalance()->toString());
        $this->assertSame(Currency::EUR, $wallet->getBalance()->getCurrency());
        $this->assertFalse($wallet->isBlocked());
        $this->assertNull($wallet->getLastActivityAt());
    }

    public function testConstructorRejectsBalanceInDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        new Wallet(
            id: 1,
            userId: 1,
            currency: Currency::PLN,
            balance: Money::of('1.00', Currency::EUR),
            reserved: Money::zero(Currency::PLN),
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function testSetIsBlocked(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setIsBlocked(true);

        $this->assertTrue($wallet->isBlocked());
    }

    public function testSetLastActivityAt(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $date = new DateTimeImmutable('2024-06-01 10:00:00');
        $wallet->setLastActivityAt($date);

        $this->assertSame($date, $wallet->getLastActivityAt());
    }

    public function testSetLastActivityAtWithNull(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setLastActivityAt(new DateTimeImmutable('2024-06-01 10:00:00'));
        $wallet->setLastActivityAt(null);

        $this->assertNull($wallet->getLastActivityAt());
    }

    public function testCreateStartsWithNothingReserved(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);

        $this->assertSame('0.00', $wallet->getReserved()->toString());
        $this->assertSame('0.00', $wallet->getAvailable()->toString());
    }

    public function testConstructorRejectsReservedInDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        new Wallet(
            id: 1,
            userId: 1,
            currency: Currency::PLN,
            balance: Money::zero(Currency::PLN),
            reserved: Money::zero(Currency::EUR),
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function testCreditIncreasesBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '10.00');

        $wallet->credit(Money::of('2.50', Currency::PLN));

        $this->assertSame('12.50', $wallet->getBalance()->toString());
        $this->assertSame('12.50', $wallet->getAvailable()->toString());
    }

    public function testCreditRejectsBlockedWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, blocked: true);

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 7 is blocked.');

        $wallet->credit(Money::of('1.00', Currency::PLN));
    }

    public function testReserveReducesAvailableButNotBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00');

        $wallet->reserve(Money::of('30.00', Currency::PLN));

        $this->assertSame('100.00', $wallet->getBalance()->toString());
        $this->assertSame('30.00', $wallet->getReserved()->toString());
        $this->assertSame('70.00', $wallet->getAvailable()->toString());
    }

    public function testReserveWholeAvailableAmount(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '60.00');

        $wallet->reserve(Money::of('40.00', Currency::PLN));

        $this->assertSame('0.00', $wallet->getAvailable()->toString());
    }

    public function testReserveRejectsMoreThanAvailable(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '60.00');

        try {
            $wallet->reserve(Money::of('40.01', Currency::PLN));
            $this->fail('Expected InsufficientFundsException.');
        } catch (InsufficientFundsException $e) {
            $this->assertSame('Insufficient funds in wallet 7.', $e->getMessage());
        }

        $this->assertSame('60.00', $wallet->getReserved()->toString());
    }

    public function testReserveFailsOnLegacyNegativeBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '-995.00');

        $this->expectException(InsufficientFundsException::class);

        $wallet->reserve(Money::of('1.00', Currency::PLN));
    }

    public function testReserveRejectsBlockedWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', blocked: true);

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 7 is blocked.');

        $wallet->reserve(Money::of('1.00', Currency::PLN));
    }

    public function testReleaseReturnsReservationToAvailable(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $wallet->release(Money::of('30.00', Currency::PLN));

        $this->assertSame('100.00', $wallet->getBalance()->toString());
        $this->assertSame('0.00', $wallet->getReserved()->toString());
    }

    public function testReleaseRejectsMoreThanReserved(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot use 30.01 PLN: only 30.00 reserved.');

        $wallet->release(Money::of('30.01', Currency::PLN));
    }

    public function testSettleTakesReservedAmountOutOfBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $wallet->settle(Money::of('30.00', Currency::PLN));

        $this->assertSame('70.00', $wallet->getBalance()->toString());
        $this->assertSame('0.00', $wallet->getReserved()->toString());
        $this->assertSame('70.00', $wallet->getAvailable()->toString());
    }

    public function testSettleRejectsMoreThanReserved(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(LogicException::class);

        $wallet->settle(Money::of('30.01', Currency::PLN));
    }

    public function testSettleIgnoresBlockade(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00', blocked: true);

        $wallet->settle(Money::of('30.00', Currency::PLN));

        $this->assertSame('70.00', $wallet->getBalance()->toString());
    }

    #[DataProvider('operationProvider')]
    public function testOperationsRejectNonPositiveAmount(string $operation): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive.');

        $wallet->{$operation}(Money::zero(Currency::PLN));
    }

    #[DataProvider('operationProvider')]
    public function testOperationsRejectNegativeAmount(string $operation): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(InvalidArgumentException::class);

        $wallet->{$operation}(Money::of('-1.00', Currency::PLN));
    }

    #[DataProvider('operationProvider')]
    public function testOperationsRejectOtherCurrency(string $operation): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(CurrencyMismatchException::class);

        $wallet->{$operation}(Money::of('1.00', Currency::EUR));
    }

    public static function operationProvider(): Generator
    {
        yield 'credit' => ['credit'];
        yield 'reserve' => ['reserve'];
        yield 'release' => ['release'];
        yield 'settle' => ['settle'];
    }
}
