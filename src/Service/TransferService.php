<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use RoundingMode;

readonly class TransferService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
        private TransactionLimits $transactionLimits,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    /**
     * Places a transfer: reserves the amount on the source wallet; balances change only when it is completed.
     *
     * @throws SameWalletTransferException
     * @throws WalletNotFoundException
     * @throws InvalidMoneyAmountException
     * @throws WalletBlockedException
     * @throws InsufficientFundsException
     */
    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        if ($fromWalletId === $toWalletId) {
            throw new SameWalletTransferException();
        }

        return $this->transactionManager->transactional(
            fn (): Transaction => $this->placeTransfer($userId, $fromWalletId, $toWalletId, $fromAmount),
        );
    }

    private function placeTransfer(int $userId, int $fromWalletId, int $toWalletId, string $fromAmount): Transaction
    {
        $this->walletRepository->lockForUpdate($fromWalletId, $toWalletId);

        $fromWallet = $this->walletRepository->findById($fromWalletId);
        if (null === $fromWallet || $fromWallet->getUserId() !== $userId || $fromWallet->isClosed()) {
            throw new WalletNotFoundException($fromWalletId);
        }

        $toWallet = $this->walletRepository->findById($toWalletId);
        if (null === $toWallet || $toWallet->getUserId() !== $userId || $toWallet->isClosed()) {
            throw new WalletNotFoundException($toWalletId);
        }

        $fromCurrency = $fromWallet->getCurrency();
        $toCurrency = $toWallet->getCurrency();

        $fromMoney = Money::of($fromAmount, $fromCurrency);

        if ($toWallet->isBlocked()) {
            throw new WalletBlockedException($toWalletId);
        }

        if ($fromWallet->isBlocked()) {
            throw new WalletBlockedException($fromWalletId);
        }

        $fromWallet->reserve($fromMoney);

        $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
        $grossToAmount = $fromMoney->convertTo($toCurrency, $exchangeRate, RoundingMode::HalfAwayFromZero);
        $spread = $this->spreadService->calculateSpread($grossToAmount, $fromCurrency, $toCurrency);
        $toAmount = $grossToAmount->subtract($spread);

        $this->walletRepository->save($fromWallet);

        $transaction = Transaction::create(
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromMoney,
            toAmount: $toAmount,
            spread: $spread,
            exchangeRate: $exchangeRate,
            requiresAntiFraudCheck: $fromMoney->isGreaterThan($this->transactionLimits->antiFraudThreshold($fromCurrency)),
        );

        $this->transactionRepository->save($transaction);

        return $transaction;
    }
}
