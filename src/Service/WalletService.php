<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransfersException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use DateTimeImmutable;

readonly class WalletService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    public function createWallet(int $userId, Currency $currency): Wallet
    {
        $existing = $this->walletRepository->findByUserIdAndCurrency($userId, $currency);

        if (null !== $existing) {
            throw new WalletAlreadyExistsException($userId, $currency);
        }

        $wallet = Wallet::create($userId, $currency);
        $this->walletRepository->save($wallet);

        return $wallet;
    }

    /**
     * Closes (soft-deletes) an empty, unblocked wallet without pending transfers. Locks the wallet first, so a
     * concurrent transfer either completes its checks before the wallet is closed or sees it closed.
     *
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws WalletNotEmptyException
     * @throws WalletHasPendingTransfersException
     */
    public function closeWallet(int $userId, int $walletId): void
    {
        $this->transactionManager->transactional(function () use ($userId, $walletId): void {
            $this->walletRepository->lockForUpdate($walletId);

            $wallet = $this->walletRepository->findById($walletId);
            if (null === $wallet || $wallet->getUserId() !== $userId || $wallet->isClosed()) {
                throw new WalletNotFoundException($walletId);
            }

            if ($wallet->isBlocked()) {
                throw new WalletBlockedException($walletId);
            }

            if (!$wallet->getBalance()->equals(Money::zero($wallet->getCurrency()))) {
                throw new WalletNotEmptyException($walletId);
            }

            if ($this->transactionRepository->hasInFlightTransfers($walletId)) {
                throw new WalletHasPendingTransfersException($walletId);
            }

            $wallet->close(new DateTimeImmutable());
            $this->walletRepository->save($wallet);
        });
    }
}
