<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Enum\Currency;
use App\Repository\DbalTransactionManager;
use App\Repository\WalletRepository;
use App\Tests\Integration\DatabaseTestCase;
use RuntimeException;

class DbalTransactionManagerTest extends DatabaseTestCase
{
    public function testReturnsOperationResult(): void
    {
        $result = $this->service(DbalTransactionManager::class)->transactional(static fn (): string => 'done');

        self::assertSame('done', $result);
    }

    public function testRollsBackOnException(): void
    {
        $user = $this->createUser();
        $wallets = $this->service(WalletRepository::class);

        try {
            $this->service(DbalTransactionManager::class)->transactional(function () use ($user): void {
                $this->createWallet($user, Currency::PLN, '10.00');
                throw new RuntimeException('boom');
            });
            self::fail('Expected exception.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertNull($wallets->findByUserIdAndCurrency($user->getIdNotNull(), Currency::PLN));
    }
}
