<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\ValueObject\Money;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use ReflectionClass;

readonly class WalletRepository implements WalletRepositoryInterface
{
    private const string TABLE_NAME = 'wallets';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Exception
     */
    public function findById(int $id): ?Wallet
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('id = :id');

        $row = $this->connection->fetchAssociative($qb->getSQL(), ['id' => $id]);

        if (!$row) {
            return null;
        }

        return $this->buildEntity($row);
    }

    /**
     * @return Wallet[]
     *
     * @throws Exception
     */
    public function findByUserId(int $userId): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('user_id = :user_id')
            ->andWhere('closed_at IS NULL');

        $rows = $this->connection->fetchAllAssociative($qb->getSQL(), ['user_id' => $userId]);

        return array_map($this->buildEntity(...), $rows);
    }

    /**
     * @throws Exception
     */
    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('user_id = :user_id')
            ->andWhere('currency = :currency')
            ->andWhere('closed_at IS NULL');

        $row = $this->connection->fetchAssociative($qb->getSQL(), [
            'user_id' => $userId,
            'currency' => $currency->value,
        ]);

        if (!$row) {
            return null;
        }

        return $this->buildEntity($row);
    }

    /**
     * @throws Exception
     */
    public function save(Wallet $wallet): void
    {
        if (null === $wallet->getId()) {
            $this->insert($wallet);
        } else {
            $this->update($wallet);
        }
    }

    /**
     * @throws Exception
     */
    public function lockForUpdate(int ...$ids): void
    {
        if ([] === $ids) {
            return;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        $this->connection->executeQuery(
            sprintf('SELECT id FROM %s WHERE id IN (?) ORDER BY id FOR UPDATE', self::TABLE_NAME),
            [$ids],
            [ArrayParameterType::INTEGER],
        );
    }

    private function buildEntity(array $row): Wallet
    {
        $currency = Currency::from($row['currency']);

        return new Wallet(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            currency: $currency,
            balance: Money::of((string) $row['balance'], $currency),
            reserved: Money::of((string) $row['reserved'], $currency),
            isBlocked: (bool) $row['is_blocked'],
            lastActivityAt: null !== $row['last_activity_at'] ? new DateTimeImmutable($row['last_activity_at']) : null,
            createdAt: new DateTimeImmutable($row['created_at']),
            closedAt: null !== $row['closed_at'] ? new DateTimeImmutable($row['closed_at']) : null,
        );
    }

    /**
     * @throws Exception
     */
    private function insert(Wallet $wallet): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->insert(self::TABLE_NAME)
            ->values([
                'user_id' => ':user_id',
                'currency' => ':currency',
                'balance' => ':balance',
                'reserved' => ':reserved',
                'is_blocked' => ':is_blocked',
                'last_activity_at' => ':last_activity_at',
                'created_at' => ':created_at',
                'closed_at' => ':closed_at',
            ]);

        $this->connection->executeQuery(
            $qb->getSQL(),
            [
                'user_id' => $wallet->getUserId(),
                'currency' => $wallet->getCurrency()->value,
                'balance' => $wallet->getBalance()->toString(),
                'reserved' => $wallet->getReserved()->toString(),
                'is_blocked' => (int) $wallet->isBlocked(),
                'last_activity_at' => $wallet->getLastActivityAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'created_at' => $wallet->getCreatedAt()
                    ->setTimezone(timezone: new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
                'closed_at' => $wallet->getClosedAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            ]
        );

        $id = (int) $this->connection->lastInsertId();

        $reflectionClass = new ReflectionClass($wallet);
        $reflectionProperty = $reflectionClass->getProperty('id');
        $reflectionProperty->setValue(objectOrValue: $wallet, value: $id);
    }

    /**
     * @throws Exception
     */
    private function update(Wallet $wallet): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->update(self::TABLE_NAME)
            ->set('balance', ':balance')
            ->set('reserved', ':reserved')
            ->set('is_blocked', ':is_blocked')
            ->set('last_activity_at', ':last_activity_at')
            ->set('closed_at', ':closed_at')
            ->where('id = :id');

        $this->connection->executeQuery(
            $qb->getSQL(),
            [
                'balance' => $wallet->getBalance()->toString(),
                'reserved' => $wallet->getReserved()->toString(),
                'is_blocked' => (int) $wallet->isBlocked(),
                'last_activity_at' => $wallet->getLastActivityAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'closed_at' => $wallet->getClosedAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'id' => $wallet->getId(),
            ]
        );
    }
}
