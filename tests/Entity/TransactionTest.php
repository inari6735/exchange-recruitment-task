<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class TransactionTest extends TestCase
{
    public function testGetters(): void
    {
        $transaction = $this->makeTransaction();

        $this->assertNull($transaction->getId());
        $this->assertSame(1, $transaction->getFromWalletId());
        $this->assertSame(2, $transaction->getToWalletId());
        $this->assertSame('100.00', $transaction->getFromAmount()->toString());
        $this->assertSame('90.00', $transaction->getToAmount()->toString());
        $this->assertSame(Currency::PLN, $transaction->getFromCurrency());
        $this->assertSame(Currency::EUR, $transaction->getToCurrency());
        $this->assertSame('0.02', $transaction->getSpread()->toString());
        $this->assertSame('0.220000', $transaction->getExchangeRate()->toString());
        $this->assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        $this->assertFalse($transaction->requiresAntiFraudCheck());
        $this->assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCreateSetsPendingStatus(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->assertSame(TransactionStatus::PENDING, $transaction->getStatus());
    }

    public function testCreateSetsFraudReviewStatus(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
    }

    public function testSetStatus(): void
    {
        $transaction = $this->makeTransaction();
        $transaction->setStatus(TransactionStatus::COMPLETED);

        $this->assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testSetAntiFraudCheckedAt(): void
    {
        $transaction = $this->makeTransaction();
        $date = new DateTimeImmutable('2024-01-01 12:00:00');
        $transaction->setAntiFraudCheckedAt($date);

        $this->assertSame($date, $transaction->getAntiFraudCheckedAt());
    }

    public function testSetAntiFraudCheckedAtWithNull(): void
    {
        $transaction = $this->makeTransaction();
        $transaction->setAntiFraudCheckedAt(new DateTimeImmutable('2024-01-01 12:00:00'));
        $transaction->setAntiFraudCheckedAt(null);

        $this->assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testConstructorRejectsSpreadInSourceCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $this->makeTransactionWith(spread: Money::of('0.02', Currency::PLN));
    }

    public function testConstructorRejectsExchangeRateFromOtherCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $this->makeTransactionWith(exchangeRate: ExchangeRate::of(Currency::USD, Currency::EUR, '0.22'));
    }

    public function testConstructorRejectsExchangeRateToOtherCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $this->makeTransactionWith(exchangeRate: ExchangeRate::of(Currency::PLN, Currency::USD, '0.22'));
    }

    private function makeTransaction(bool $requiresAntiFraudCheck = false): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('90.00', Currency::EUR),
            spread: Money::of('0.02', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.22'),
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
    }

    private function makeTransactionWith(?Money $spread = null, ?ExchangeRate $exchangeRate = null): Transaction
    {
        return new Transaction(
            id: 1,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('90.00', Currency::EUR),
            spread: $spread ?? Money::of('0.02', Currency::EUR),
            exchangeRate: $exchangeRate ?? ExchangeRate::of(Currency::PLN, Currency::EUR, '0.22'),
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
