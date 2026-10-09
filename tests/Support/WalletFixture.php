<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\ValueObject\Money;
use DateTimeImmutable;

final class WalletFixture
{
    /**
     * Builds a persisted-looking wallet (with id) in any state, bypassing domain operations.
     */
    public static function create(
        int $id,
        int $userId,
        Currency $currency,
        string $balance = '0',
        string $reserved = '0',
        bool $blocked = false,
        bool $closed = false,
    ): Wallet {
        return new Wallet(
            id: $id,
            userId: $userId,
            currency: $currency,
            balance: Money::of($balance, $currency),
            reserved: Money::of($reserved, $currency),
            isBlocked: $blocked,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
            closedAt: $closed ? new DateTimeImmutable('-1 day') : null,
        );
    }
}
