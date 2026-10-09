<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\TransactionAlreadyProcessedException;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use ReflectionClass;

readonly class TransactionRepository implements TransactionRepositoryInterface
{
    private const string TABLE_NAME = 'transactions';

    public function __construct(private Connection $connection)
    {
    }

    /**
     * @throws Exception
     */
    public function findById(int $id): ?Transaction
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
     * @return Transaction[]
     *
     * @throws Exception
     */
    public function findByWalletId(int $walletId): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('from_wallet_id = :wallet_id')
            ->orWhere('to_wallet_id = :wallet_id');

        $rows = $this->connection->fetchAllAssociative($qb->getSQL(), ['wallet_id' => $walletId]);

        return array_map($this->buildEntity(...), $rows);
    }

    /**
     * @return Transaction[]
     *
     * @throws Exception
     */
    public function findByStatus(TransactionStatus $status): array
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('*')
            ->from(self::TABLE_NAME)
            ->where('status = :status');

        $rows = $this->connection->fetchAllAssociative($qb->getSQL(), ['status' => $status->value]);

        return array_map($this->buildEntity(...), $rows);
    }

    /**
     * @throws Exception
     */
    public function hasInFlightTransfers(int $walletId): bool
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('1')
            ->from(self::TABLE_NAME)
            ->where('(from_wallet_id = :wallet_id OR to_wallet_id = :wallet_id)')
            ->andWhere('status IN (:pending, :fraud_review)')
            ->setMaxResults(1);

        return false !== $this->connection->fetchOne($qb->getSQL(), [
            'wallet_id' => $walletId,
            'pending' => TransactionStatus::PENDING->value,
            'fraud_review' => TransactionStatus::FRAUD_REVIEW->value,
        ]);
    }

    /**
     * @throws Exception
     */
    public function save(Transaction $transaction): void
    {
        if (null === $transaction->getId()) {
            $this->insert($transaction);
        } else {
            $this->update($transaction);
        }
    }

    private function buildEntity(array $row): Transaction
    {
        $fromCurrency = Currency::from($row['from_currency']);
        $toCurrency = Currency::from($row['to_currency']);

        return new Transaction(
            id: (int) $row['id'],
            fromWalletId: (int) $row['from_wallet_id'],
            toWalletId: (int) $row['to_wallet_id'],
            fromAmount: Money::of((string) $row['from_amount'], $fromCurrency),
            toAmount: Money::of((string) $row['to_amount'], $toCurrency),
            spread: Money::of((string) $row['spread'], $toCurrency),
            exchangeRate: ExchangeRate::of($fromCurrency, $toCurrency, (string) $row['exchange_rate']),
            status: TransactionStatus::from($row['status']),
            requiresAntiFraudCheck: (bool) $row['requires_anti_fraud_check'],
            antiFraudCheckedAt: null !== $row['anti_fraud_checked_at']
                ? new DateTimeImmutable($row['anti_fraud_checked_at'])
                : null,
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }

    /**
     * @throws Exception
     */
    private function insert(Transaction $transaction): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->insert(self::TABLE_NAME)
            ->values([
                'from_wallet_id' => ':from_wallet_id',
                'to_wallet_id' => ':to_wallet_id',
                'from_amount' => ':from_amount',
                'to_amount' => ':to_amount',
                'from_currency' => ':from_currency',
                'to_currency' => ':to_currency',
                'spread' => ':spread',
                'exchange_rate' => ':exchange_rate',
                'status' => ':status',
                'requires_anti_fraud_check' => ':requires_anti_fraud_check',
                'anti_fraud_checked_at' => ':anti_fraud_checked_at',
                'created_at' => ':created_at',
            ]);

        $this->connection->executeQuery(
            $qb->getSQL(),
            [
                'from_wallet_id' => $transaction->getFromWalletId(),
                'to_wallet_id' => $transaction->getToWalletId(),
                'from_amount' => $transaction->getFromAmount()->toString(),
                'to_amount' => $transaction->getToAmount()->toString(),
                'from_currency' => $transaction->getFromCurrency()->value,
                'to_currency' => $transaction->getToCurrency()->value,
                'spread' => $transaction->getSpread()->toString(),
                'exchange_rate' => $transaction->getExchangeRate()->toString(),
                'status' => $transaction->getStatus()->value,
                'requires_anti_fraud_check' => (int) $transaction->requiresAntiFraudCheck(),
                'anti_fraud_checked_at' => $transaction->getAntiFraudCheckedAt()
                    ?->setTimezone(timezone: new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
                'created_at' => $transaction->getCreatedAt()
                    ->setTimezone(timezone: new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
            ]
        );

        $id = (int) $this->connection->lastInsertId();

        $reflectionClass = new ReflectionClass($transaction);
        $reflectionProperty = $reflectionClass->getProperty('id');
        $reflectionProperty->setValue(objectOrValue: $transaction, value: $id);
    }

    /**
     * Only an in-flight transaction (pending or fraud review) can change its status, so a transaction is settled once.
     *
     * @throws Exception
     * @throws TransactionAlreadyProcessedException
     */
    private function update(Transaction $transaction): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->update(self::TABLE_NAME)
            ->set('status', ':status')
            ->set('anti_fraud_checked_at', ':anti_fraud_checked_at')
            ->where('id = :id')
            ->andWhere('status IN (:pending, :fraud_review)');

        $affectedRows = $this->connection->executeStatement(
            $qb->getSQL(),
            [
                'status' => $transaction->getStatus()->value,
                'anti_fraud_checked_at' => $transaction->getAntiFraudCheckedAt()
                    ?->setTimezone(timezone: new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
                'id' => $transaction->getId(),
                'pending' => TransactionStatus::PENDING->value,
                'fraud_review' => TransactionStatus::FRAUD_REVIEW->value,
            ]
        );

        if (0 === $affectedRows) {
            throw new TransactionAlreadyProcessedException((int) $transaction->getId());
        }
    }
}
