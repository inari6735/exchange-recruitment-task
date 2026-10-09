<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Repository\WalletRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

class WalletClosingPersistenceTest extends DatabaseTestCase
{
    public function testPersistsClosedAt(): void
    {
        $wallet = $this->createWallet($this->createUser(), Currency::PLN);
        $wallet->close(new DateTimeImmutable('2026-10-09 12:00:00'));

        $repository = $this->service(WalletRepository::class);
        $repository->save($wallet);
        $reloaded = $repository->findById((int) $wallet->getId());

        self::assertTrue($reloaded?->isClosed());
        self::assertSame('2026-10-09 12:00:00', $reloaded?->getClosedAt()?->format('Y-m-d H:i:s'));
    }

    public function testFindersReturnOnlyOpenWallets(): void
    {
        $user = $this->createUser();
        $repository = $this->service(WalletRepository::class);
        $closed = $this->createWallet($user, Currency::PLN);
        $closed->close(new DateTimeImmutable());
        $repository->save($closed);
        $open = $this->createWallet($user, Currency::PLN);

        $byUser = $repository->findByUserId($user->getIdNotNull());

        self::assertSame([$open->getId()], array_map(static fn (Wallet $w): ?int => $w->getId(), $byUser));
        self::assertSame($open->getId(), $repository->findByUserIdAndCurrency($user->getIdNotNull(), Currency::PLN)?->getId());
        self::assertTrue($repository->findById((int) $closed->getId())?->isClosed());
    }

    public function testManyClosedWalletsInSameCurrencyAreAllowed(): void
    {
        $user = $this->createUser();
        $repository = $this->service(WalletRepository::class);

        foreach ([1, 2] as $ignored) {
            $wallet = $this->createWallet($user, Currency::EUR);
            $wallet->close(new DateTimeImmutable());
            $repository->save($wallet);
        }
        $this->createWallet($user, Currency::EUR);

        self::assertCount(1, $repository->findByUserId($user->getIdNotNull()));
    }

    public function testTwoOpenWalletsInSameCurrencyViolateUniqueness(): void
    {
        $user = $this->createUser();
        $this->createWallet($user, Currency::USD);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createWallet($user, Currency::USD);
    }
}
