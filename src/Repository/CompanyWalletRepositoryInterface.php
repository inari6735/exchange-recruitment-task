<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CompanyWallet;
use App\Enum\Currency;
use App\ValueObject\Money;

interface CompanyWalletRepositoryInterface
{
    public function findByCurrency(Currency $currency): ?CompanyWallet;

    /** @return CompanyWallet[] */
    public function findAll(): array;

    public function addToBalance(Money $amount): void;
}
