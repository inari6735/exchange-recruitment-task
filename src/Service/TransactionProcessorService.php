<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Exception\TransactionAlreadyProcessedException;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    /**
     * Settles the reservation on the source wallet, credits the target and books the spread for the company.
     * A missing or blocked wallet rejects the transaction instead.
     *
     * @throws TransactionAlreadyProcessedException
     */
    public function complete(Transaction $transaction): void
    {
        $this->transactionManager->transactional(function () use ($transaction): void {
            $this->walletRepository->lockForUpdate($transaction->getFromWalletId(), $transaction->getToWalletId());

            $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
            // A same-wallet transaction (legacy) must settle and credit one object, or the second save overwrites the first.
            $toWallet = $transaction->getFromWalletId() === $transaction->getToWalletId()
                ? $fromWallet
                : $this->walletRepository->findById($transaction->getToWalletId());

            if (null === $fromWallet || null === $toWallet
                || $fromWallet->isBlocked() || $toWallet->isBlocked()
                || $fromWallet->isClosed() || $toWallet->isClosed()) {
                $this->rejectLocked($transaction, $fromWallet);

                return;
            }

            // Claim the transaction first: the guarded status update fails if another run already processed it.
            $transaction->setStatus(TransactionStatus::COMPLETED);
            $this->markAntiFraudChecked($transaction);
            $this->transactionRepository->save($transaction);

            $now = new DateTimeImmutable();

            $fromWallet->settle($transaction->getFromAmount());
            $fromWallet->setLastActivityAt($now);

            $toWallet->credit($transaction->getToAmount());
            $toWallet->setLastActivityAt($now);

            $this->walletRepository->save($fromWallet);
            if ($toWallet !== $fromWallet) {
                $this->walletRepository->save($toWallet);
            }

            $spread = $transaction->getSpread();
            if ($spread->isGreaterThan(Money::zero($spread->getCurrency()))) {
                $this->companyWalletRepository->addToBalance($spread);
            }
        });
    }

    /**
     * Releases the reservation on the source wallet.
     *
     * @throws TransactionAlreadyProcessedException
     */
    public function reject(Transaction $transaction): void
    {
        $this->transactionManager->transactional(function () use ($transaction): void {
            $this->walletRepository->lockForUpdate($transaction->getFromWalletId());

            $this->rejectLocked($transaction, $this->walletRepository->findById($transaction->getFromWalletId()));
        });
    }

    private function rejectLocked(Transaction $transaction, ?Wallet $fromWallet): void
    {
        $transaction->setStatus(TransactionStatus::REJECTED);
        $this->markAntiFraudChecked($transaction);
        $this->transactionRepository->save($transaction);

        if (null !== $fromWallet) {
            $fromWallet->release($transaction->getFromAmount());
            $this->walletRepository->save($fromWallet);
        }
    }

    private function markAntiFraudChecked(Transaction $transaction): void
    {
        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }
    }
}
