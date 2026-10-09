<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use DateTimeImmutable;

class Transaction
{
    public function __construct(
        private ?int $id,
        private readonly int $fromWalletId,
        private readonly int $toWalletId,
        private readonly Money $fromAmount,
        private readonly Money $toAmount,
        private readonly Money $spread,
        private readonly ExchangeRate $exchangeRate,
        private TransactionStatus $status,
        private readonly bool $requiresAntiFraudCheck,
        private ?DateTimeImmutable $antiFraudCheckedAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ($exchangeRate->getFrom() !== $fromAmount->getCurrency()) {
            throw new CurrencyMismatchException($fromAmount->getCurrency(), $exchangeRate->getFrom());
        }

        if ($exchangeRate->getTo() !== $toAmount->getCurrency()) {
            throw new CurrencyMismatchException($toAmount->getCurrency(), $exchangeRate->getTo());
        }

        if ($spread->getCurrency() !== $toAmount->getCurrency()) {
            throw new CurrencyMismatchException($toAmount->getCurrency(), $spread->getCurrency());
        }
    }

    public static function create(
        int $fromWalletId,
        int $toWalletId,
        Money $fromAmount,
        Money $toAmount,
        Money $spread,
        ExchangeRate $exchangeRate,
        bool $requiresAntiFraudCheck,
    ): self {
        return new self(
            id: null,
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromAmount,
            toAmount: $toAmount,
            spread: $spread,
            exchangeRate: $exchangeRate,
            status: $requiresAntiFraudCheck
                ? TransactionStatus::FRAUD_REVIEW
                : TransactionStatus::PENDING,
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromWalletId(): int
    {
        return $this->fromWalletId;
    }

    public function getToWalletId(): int
    {
        return $this->toWalletId;
    }

    public function getFromAmount(): Money
    {
        return $this->fromAmount;
    }

    public function getToAmount(): Money
    {
        return $this->toAmount;
    }

    public function getFromCurrency(): Currency
    {
        return $this->fromAmount->getCurrency();
    }

    public function getToCurrency(): Currency
    {
        return $this->toAmount->getCurrency();
    }

    public function getSpread(): Money
    {
        return $this->spread;
    }

    public function getExchangeRate(): ExchangeRate
    {
        return $this->exchangeRate;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function requiresAntiFraudCheck(): bool
    {
        return $this->requiresAntiFraudCheck;
    }

    public function getAntiFraudCheckedAt(): ?DateTimeImmutable
    {
        return $this->antiFraudCheckedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setStatus(TransactionStatus $status): void
    {
        $this->status = $status;
    }

    public function setAntiFraudCheckedAt(?DateTimeImmutable $antiFraudCheckedAt): void
    {
        $this->antiFraudCheckedAt = $antiFraudCheckedAt;
    }
}
