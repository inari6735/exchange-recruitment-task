<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;

interface WalletRepositoryInterface
{
    public function findById(int $id): ?Wallet;

    /**
     * Open wallets only.
     *
     * @return Wallet[]
     */
    public function findByUserId(int $userId): array;

    /**
     * The user's open wallet in the currency, if any.
     */
    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet;

    public function save(Wallet $wallet): void;

    /**
     * Locks the wallets' rows until the current database transaction ends.
     * Ids are deduplicated and locked in ascending order, so concurrent callers cannot deadlock each other.
     */
    public function lockForUpdate(int ...$ids): void;
}
