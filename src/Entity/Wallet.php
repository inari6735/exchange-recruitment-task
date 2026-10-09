<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\Exception\InsufficientFundsException;
use App\Exception\WalletBlockedException;
use App\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

class Wallet
{
    /**
     * @param Money $reserved sum of outgoing transfers waiting for processing; available = balance − reserved
     */
    public function __construct(
        private ?int $id,
        private readonly int $userId,
        private readonly Currency $currency,
        private Money $balance,
        private Money $reserved,
        private bool $isBlocked,
        private ?DateTimeImmutable $lastActivityAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
        $this->assertCurrency($balance);
        $this->assertCurrency($reserved);
    }

    public static function create(int $userId, Currency $currency): self
    {
        return new self(
            id: null,
            userId: $userId,
            currency: $currency,
            balance: Money::zero($currency),
            reserved: Money::zero($currency),
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBalance(): Money
    {
        return $this->balance;
    }

    public function getReserved(): Money
    {
        return $this->reserved;
    }

    public function getAvailable(): Money
    {
        return $this->balance->subtract($this->reserved);
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setIsBlocked(bool $isBlocked): void
    {
        $this->isBlocked = $isBlocked;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): void
    {
        $this->lastActivityAt = $lastActivityAt;
    }

    /**
     * Adds money to the balance (deposit or incoming transfer).
     */
    public function credit(Money $amount): void
    {
        $this->assertPositive($amount);
        $this->assertNotBlocked();

        $this->balance = $this->balance->add($amount);
    }

    /**
     * Blocks money for an outgoing transfer until it is settled or released.
     */
    public function reserve(Money $amount): void
    {
        $this->assertPositive($amount);
        $this->assertNotBlocked();

        if ($amount->isGreaterThan($this->getAvailable())) {
            throw new InsufficientFundsException($this->id ?? 0);
        }

        $this->reserved = $this->reserved->add($amount);
    }

    /**
     * Gives reserved money back to the available pool (rejected transfer).
     */
    public function release(Money $amount): void
    {
        $this->assertPositive($amount);
        $this->assertReserved($amount);

        $this->reserved = $this->reserved->subtract($amount);
    }

    /**
     * Takes reserved money out of the wallet (completed transfer). Works on a blocked wallet on purpose:
     * callers decide whether a blocked wallet may settle.
     */
    public function settle(Money $amount): void
    {
        $this->assertPositive($amount);
        $this->assertReserved($amount);

        $this->balance = $this->balance->subtract($amount);
        $this->reserved = $this->reserved->subtract($amount);
    }

    private function assertCurrency(Money $money): void
    {
        if ($money->getCurrency() !== $this->currency) {
            throw new CurrencyMismatchException($this->currency, $money->getCurrency());
        }
    }

    private function assertPositive(Money $amount): void
    {
        $this->assertCurrency($amount);

        if (!$amount->isGreaterThan(Money::zero($this->currency))) {
            throw new InvalidArgumentException('Amount must be positive.');
        }
    }

    private function assertNotBlocked(): void
    {
        if ($this->isBlocked) {
            throw new WalletBlockedException($this->id ?? 0);
        }
    }

    private function assertReserved(Money $amount): void
    {
        if ($amount->isGreaterThan($this->reserved)) {
            throw new LogicException(sprintf('Cannot use %s %s: only %s reserved.', $amount->toString(), $this->currency->value, $this->reserved->toString()));
        }
    }
}
