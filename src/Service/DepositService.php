<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Exception\DepositLimitExceededException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionManagerInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use DateTimeImmutable;

readonly class DepositService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionLimits $transactionLimits,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    /**
     * @throws WalletNotFoundException
     * @throws InvalidMoneyAmountException
     * @throws DepositLimitExceededException
     * @throws WalletBlockedException
     */
    public function deposit(int $userId, int $walletId, string $amount): Wallet
    {
        return $this->transactionManager->transactional(function () use ($userId, $walletId, $amount): Wallet {
            $this->walletRepository->lockForUpdate($walletId);

            $wallet = $this->walletRepository->findById($walletId);
            if (null === $wallet || $wallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($walletId);
            }

            $money = Money::of($amount, $wallet->getCurrency());

            $limit = $this->transactionLimits->depositLimit($wallet->getCurrency());
            if ($money->isGreaterThan($limit)) {
                throw new DepositLimitExceededException($limit);
            }

            if ($wallet->isBlocked()) {
                throw new WalletBlockedException($walletId);
            }

            $wallet->credit($money);
            $wallet->setLastActivityAt(new DateTimeImmutable());
            $this->walletRepository->save($wallet);

            return $wallet;
        });
    }
}
