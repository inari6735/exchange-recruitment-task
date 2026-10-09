<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Enum\Currency;
use App\Repository\WalletRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\ValueObject\Money;

class WalletRepositoryReservedTest extends DatabaseTestCase
{
    public function testPersistsReservedAmount(): void
    {
        $wallet = $this->createWallet($this->createUser(), Currency::JPY, '1000');
        $wallet->reserve(Money::of('250', Currency::JPY));

        $repository = $this->service(WalletRepository::class);
        $repository->save($wallet);
        $reloaded = $repository->findById((int) $wallet->getId());

        self::assertSame('1000', $reloaded?->getBalance()->toString());
        self::assertSame('250', $reloaded?->getReserved()->toString());
        self::assertSame('750', $reloaded?->getAvailable()->toString());
    }

    public function testLockForUpdateAcceptsDuplicatesAndUnknownIds(): void
    {
        $wallet = $this->createWallet($this->createUser(), Currency::PLN);

        $this->service(WalletRepository::class)->lockForUpdate((int) $wallet->getId(), 999999999, (int) $wallet->getId());

        $this->addToAssertionCount(1);
    }
}
