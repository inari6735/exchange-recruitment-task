<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\Money;
use DateTimeImmutable;

class CompanyWallet
{
    public function __construct(
        private ?int $id,
        private readonly Currency $currency,
        private Money $balance,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->assertBalanceCurrency($balance);
    }

    public static function create(Currency $currency): self
    {
        $now = new DateTimeImmutable();

        return new self(
            id: null,
            currency: $currency,
            balance: Money::zero($currency),
            createdAt: $now,
            updatedAt: $now,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBalance(): Money
    {
        return $this->balance;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setBalance(Money $balance): void
    {
        $this->assertBalanceCurrency($balance);
        $this->balance = $balance;
    }

    private function assertBalanceCurrency(Money $balance): void
    {
        if ($balance->getCurrency() !== $this->currency) {
            throw new CurrencyMismatchException($this->currency, $balance->getCurrency());
        }
    }
}
