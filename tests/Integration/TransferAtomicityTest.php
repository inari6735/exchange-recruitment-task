<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Transaction;
use App\Entity\User;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\DbalTransactionManager;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\UserRepository;
use App\Repository\WalletRepository;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use App\Tests\Support\TransactionLimitsFactory;
use App\ValueObject\Money;
use DateTimeImmutable;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransferAtomicityTest extends KernelTestCase
{
    public function testWalletReservationIsRolledBackWhenSavingTransactionFails(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $connection = $container->get('doctrine.dbal.default_connection');
        $wallets = $container->get(WalletRepository::class);

        $user = new User(null, bin2hex(random_bytes(8)).'@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $container->get(UserRepository::class)->save($user);
        $pln = Wallet::create($user->getIdNotNull(), Currency::PLN);
        $pln->credit(Money::of('500.00', Currency::PLN));
        $wallets->save($pln);
        $eur = Wallet::create($user->getIdNotNull(), Currency::EUR);
        $wallets->save($eur);

        $failingTransactions = new class implements TransactionRepositoryInterface {
            public function findById(int $id): ?Transaction
            {
                return null;
            }

            public function findByWalletId(int $walletId): array
            {
                return [];
            }

            public function findByStatus(TransactionStatus $status): array
            {
                return [];
            }

            public function hasInFlightTransfers(int $walletId): bool
            {
                return false;
            }

            public function save(Transaction $transaction): void
            {
                throw new RuntimeException('transaction insert failed');
            }
        };

        $service = new TransferService(
            $wallets,
            $failingTransactions,
            new ExchangeRateService(),
            new SpreadService(),
            TransactionLimitsFactory::default(),
            new DbalTransactionManager($connection),
        );

        self::assertFalse($connection->isTransactionActive(), 'must run at top level, not inside a test transaction');

        try {
            $service->transfer($user->getIdNotNull(), (int) $pln->getId(), (int) $eur->getId(), '100.00');
            self::fail('Expected the transaction save to fail.');
        } catch (RuntimeException $e) {
            self::assertSame('transaction insert failed', $e->getMessage());
        }

        $row = $connection->fetchAssociative('SELECT balance, reserved FROM wallets WHERE id = ?', [$pln->getId()]);
        self::assertSame(['balance' => '500.0000', 'reserved' => '0.0000'], $row);
    }
}
