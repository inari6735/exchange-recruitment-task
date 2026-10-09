<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\TransactionResponse;
use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

class TransactionResponseTest extends TestCase
{
    private function makeTransaction(TransactionStatus $status = TransactionStatus::PENDING): Transaction
    {
        return new Transaction(
            id: 7,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('25.12', Currency::EUR),
            spread: Money::of('0.13', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            status: $status,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );
    }

    public function testJsonSerializeReturnsAllFields(): void
    {
        $data = new TransactionResponse($this->makeTransaction())->jsonSerialize();

        self::assertSame(7, $data['id']);
        self::assertSame(1, $data['fromWalletId']);
        self::assertSame(2, $data['toWalletId']);
        self::assertSame('100.00', $data['fromAmount']);
        self::assertSame('25.12', $data['toAmount']);
        self::assertSame('PLN', $data['fromCurrency']);
        self::assertSame('EUR', $data['toCurrency']);
        self::assertSame('0.13', $data['spread']);
        self::assertSame('0.250000', $data['exchangeRate']);
        self::assertSame('pending', $data['status']);
        self::assertSame(
            new DateTimeImmutable('2026-01-15T10:00:00+00:00')->format(DateTimeInterface::ATOM),
            $data['createdAt'],
        );
    }

    public function testJsonSerializeWithFraudReviewStatus(): void
    {
        $data = new TransactionResponse($this->makeTransaction(TransactionStatus::FRAUD_REVIEW))->jsonSerialize();

        self::assertSame('fraud_review', $data['status']);
    }

    public function testJsonSerializeWithNullId(): void
    {
        $transaction = new Transaction(
            id: null,
            fromWalletId: 3,
            toWalletId: 4,
            fromAmount: Money::of('50.00', Currency::USD),
            toAmount: Money::of('50.00', Currency::USD),
            spread: Money::zero(Currency::USD),
            exchangeRate: ExchangeRate::of(Currency::USD, Currency::USD, '1'),
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $data = new TransactionResponse($transaction)->jsonSerialize();

        self::assertNull($data['id']);
    }
}
