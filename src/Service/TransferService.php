<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use RoundingMode;

readonly class TransferService
{
    public const string ANTI_FRAUD_THRESHOLD = '15000';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
    ) {
    }

    /**
     * @throws WalletNotFoundException
     * @throws InvalidMoneyAmountException
     */
    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        $fromWallet = $this->walletRepository->findById($fromWalletId);
        if (null === $fromWallet || $fromWallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($fromWalletId);
        }

        $toWallet = $this->walletRepository->findById($toWalletId);
        if (null === $toWallet || $toWallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($toWalletId);
        }

        $fromCurrency = $fromWallet->getCurrency();
        $toCurrency = $toWallet->getCurrency();

        $fromMoney = Money::of($fromAmount, $fromCurrency);
        $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
        $grossToAmount = $fromMoney->convertTo($toCurrency, $exchangeRate, RoundingMode::HalfAwayFromZero);
        $spread = $this->spreadService->calculateSpread($grossToAmount, $fromCurrency, $toCurrency);
        $toAmount = $grossToAmount->subtract($spread);

        $fromWallet->setBalance($fromWallet->getBalance()->subtract($fromMoney));
        $toWallet->setBalance($toWallet->getBalance()->add($toAmount));

        $this->walletRepository->save($fromWallet);
        $this->walletRepository->save($toWallet);

        $transaction = Transaction::create(
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromMoney,
            toAmount: $toAmount,
            spread: $spread,
            exchangeRate: $exchangeRate,
            requiresAntiFraudCheck: $toAmount->isGreaterThan(Money::of(self::ANTI_FRAUD_THRESHOLD, $toCurrency)),
        );

        $this->transactionRepository->save($transaction);

        return $transaction;
    }
}
