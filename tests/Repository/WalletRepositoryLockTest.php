<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\WalletRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

class WalletRepositoryLockTest extends TestCase
{
    public function testLocksDistinctIdsInAscendingOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeQuery')
            ->with(
                'SELECT id FROM wallets WHERE id IN (?) ORDER BY id FOR UPDATE',
                [[3, 9]],
                [ArrayParameterType::INTEGER],
            )
            ->willReturn($this->createStub(Result::class));

        new WalletRepository($connection)->lockForUpdate(9, 3, 9);
    }

    public function testDoesNothingWithoutIds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeQuery');

        new WalletRepository($connection)->lockForUpdate();
    }
}
