<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Repository\UserRepository;
use App\Repository\WalletRepository;
use App\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs each test inside a database transaction that is rolled back afterwards (database: app_test).
 * Services' own transactions nest as savepoints.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        return self::getContainer()->get($id);
    }

    protected function createUser(): User
    {
        $user = new User(
            id: null,
            email: bin2hex(random_bytes(8)).'@example.com',
            roles: ['ROLE_USER'],
            createdAt: new DateTimeImmutable(),
        );
        $this->service(UserRepository::class)->save($user);

        return $user;
    }

    protected function createWallet(User $user, Currency $currency, string $balance = '0'): Wallet
    {
        $wallet = Wallet::create($user->getIdNotNull(), $currency);
        $amount = Money::of($balance, $currency);
        if ($amount->isGreaterThan(Money::zero($currency))) {
            $wallet->credit($amount);
        }
        $this->service(WalletRepository::class)->save($wallet);

        return $wallet;
    }
}
