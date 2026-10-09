<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use DateTimeImmutable;

readonly class DepositService
{
    public const string MAX_AMOUNT = '10000';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
    ) {
    }

    /**
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws InvalidMoneyAmountException
     */
    public function deposit(int $userId, int $walletId, string $amount): Wallet
    {
        $wallet = $this->walletRepository->findById($walletId);
        if (null === $wallet || $wallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($walletId);
        }

        if ($wallet->isBlocked()) {
            throw new WalletBlockedException($walletId);
        }

        $wallet->setBalance($wallet->getBalance()->add(Money::of($amount, $wallet->getCurrency())));
        $wallet->setLastActivityAt(new DateTimeImmutable());
        $this->walletRepository->save($wallet);

        return $wallet;
    }
}
