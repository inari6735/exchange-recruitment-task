<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\TransactionAlreadyProcessedException;
use App\Repository\TransactionRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;

class TransactionRepositoryTest extends DatabaseTestCase
{
    public function testSecondStatusChangeFromStaleCopyIsRefused(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $id = $this->createPendingTransaction();

        $first = $repository->findById($id);
        $stale = $repository->findById($id);

        $first?->setStatus(TransactionStatus::COMPLETED);
        $repository->save($first);

        $stale?->setStatus(TransactionStatus::REJECTED);

        try {
            $repository->save($stale);
            self::fail('Expected TransactionAlreadyProcessedException.');
        } catch (TransactionAlreadyProcessedException $e) {
            self::assertSame(sprintf('Transaction %d was already processed.', $id), $e->getMessage());
        }

        self::assertSame(TransactionStatus::COMPLETED, $repository->findById($id)?->getStatus());
    }

    public function testStatusChangeFromFraudReviewIsAllowed(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $id = $this->createPendingTransaction(requiresAntiFraudCheck: true);

        $transaction = $repository->findById($id);
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction?->getStatus());

        $transaction->setStatus(TransactionStatus::REJECTED);
        $repository->save($transaction);

        self::assertSame(TransactionStatus::REJECTED, $repository->findById($id)?->getStatus());
    }

    public function testHasInFlightTransfersForSourceAndTarget(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $transaction = $repository->findById($this->createPendingTransaction());

        self::assertTrue($repository->hasInFlightTransfers((int) $transaction?->getFromWalletId()));
        self::assertTrue($repository->hasInFlightTransfers((int) $transaction?->getToWalletId()));
    }

    public function testFraudReviewCountsAsInFlight(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $transaction = $repository->findById($this->createPendingTransaction(requiresAntiFraudCheck: true));

        self::assertTrue($repository->hasInFlightTransfers((int) $transaction?->getToWalletId()));
    }

    public function testSettledTransfersAreNotInFlight(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $transaction = $repository->findById($this->createPendingTransaction());
        $transaction?->setStatus(TransactionStatus::COMPLETED);
        $repository->save($transaction);

        self::assertFalse($repository->hasInFlightTransfers((int) $transaction?->getFromWalletId()));
        self::assertFalse($repository->hasInFlightTransfers((int) $transaction?->getToWalletId()));
    }

    public function testUnrelatedWalletHasNoInFlightTransfers(): void
    {
        $this->createPendingTransaction();
        $other = $this->createWallet($this->createUser(), Currency::GBP);

        self::assertFalse($this->service(TransactionRepository::class)->hasInFlightTransfers((int) $other->getId()));
    }

    private function createPendingTransaction(bool $requiresAntiFraudCheck = false): int
    {
        $user = $this->createUser();
        $from = $this->createWallet($user, Currency::PLN, '100.00');
        $to = $this->createWallet($user, Currency::EUR);

        $transaction = Transaction::create(
            fromWalletId: (int) $from->getId(),
            toWalletId: (int) $to->getId(),
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('23.43', Currency::EUR),
            spread: Money::of('0.16', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.235910'),
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
        $this->service(TransactionRepository::class)->save($transaction);

        return (int) $transaction->getId();
    }
}
