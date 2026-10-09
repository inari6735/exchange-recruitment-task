<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\TransactionAlreadyProcessedException;
use App\Repository\CompanyWalletRepository;
use App\Repository\TransactionRepository;
use App\Repository\WalletRepository;
use App\Service\TransactionProcessorService;
use App\Service\TransferService;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;

class TransferFlowTest extends DatabaseTestCase
{
    public function testCompletedTransferMovesMoneyOnceAndBooksSpread(): void
    {
        $user = $this->createUser();
        $pln = (int) $this->createWallet($user, Currency::PLN, '500.00')->getId();
        $eur = (int) $this->createWallet($user, Currency::EUR)->getId();
        $companyBefore = $this->companyBalance(Currency::EUR);

        $placed = $this->service(TransferService::class)->transfer($user->getIdNotNull(), $pln, $eur, '100.00');

        $this->assertWallet($pln, '500.00', '100.00');
        $this->assertWallet($eur, '0.00', '0.00');

        $this->service(TransactionProcessorService::class)->complete($this->reload((int) $placed->getId()));

        $this->assertWallet($pln, '400.00', '0.00');
        $this->assertWallet($eur, '23.43', '0.00');
        self::assertSame($companyBefore->add(Money::of('0.16', Currency::EUR))->toString(), $this->companyBalance(Currency::EUR)->toString());
        self::assertSame(TransactionStatus::COMPLETED, $this->reload((int) $placed->getId())->getStatus());
    }

    public function testRejectedTransferLeavesBalancesUntouched(): void
    {
        $user = $this->createUser();
        $pln = (int) $this->createWallet($user, Currency::PLN, '500.00')->getId();
        $eur = (int) $this->createWallet($user, Currency::EUR)->getId();
        $companyBefore = $this->companyBalance(Currency::EUR);

        $placed = $this->service(TransferService::class)->transfer($user->getIdNotNull(), $pln, $eur, '100.00');
        $this->service(TransactionProcessorService::class)->reject($this->reload((int) $placed->getId()));

        $this->assertWallet($pln, '500.00', '0.00');
        $this->assertWallet($eur, '0.00', '0.00');
        self::assertSame($companyBefore->toString(), $this->companyBalance(Currency::EUR)->toString());
        self::assertSame(TransactionStatus::REJECTED, $this->reload((int) $placed->getId())->getStatus());
    }

    public function testTransactionCannotBeSettledTwice(): void
    {
        $user = $this->createUser();
        $pln = (int) $this->createWallet($user, Currency::PLN, '500.00')->getId();
        $eur = (int) $this->createWallet($user, Currency::EUR)->getId();
        $companyBefore = $this->companyBalance(Currency::EUR);

        $placed = $this->service(TransferService::class)->transfer($user->getIdNotNull(), $pln, $eur, '100.00');
        $firstWorkerCopy = $this->reload((int) $placed->getId());
        $secondWorkerCopy = $this->reload((int) $placed->getId());

        $this->service(TransactionProcessorService::class)->complete($firstWorkerCopy);

        try {
            $this->service(TransactionProcessorService::class)->complete($secondWorkerCopy);
            self::fail('Expected TransactionAlreadyProcessedException.');
        } catch (TransactionAlreadyProcessedException) {
        }

        $this->assertWallet($pln, '400.00', '0.00');
        $this->assertWallet($eur, '23.43', '0.00');
        self::assertSame($companyBefore->add(Money::of('0.16', Currency::EUR))->toString(), $this->companyBalance(Currency::EUR)->toString());
    }

    public function testInsufficientFundsChangesNothing(): void
    {
        $user = $this->createUser();
        $pln = (int) $this->createWallet($user, Currency::PLN, '50.00')->getId();
        $eur = (int) $this->createWallet($user, Currency::EUR)->getId();

        try {
            $this->service(TransferService::class)->transfer($user->getIdNotNull(), $pln, $eur, '100.00');
            self::fail('Expected InsufficientFundsException.');
        } catch (InsufficientFundsException) {
        }

        $this->assertWallet($pln, '50.00', '0.00');
        self::assertSame([], $this->service(TransactionRepository::class)->findByWalletId($pln));
    }

    public function testLegacySameWalletTransactionCompletesWithoutCreatingMoney(): void
    {
        $user = $this->createUser();
        $walletId = (int) $this->createWallet($user, Currency::PLN, '100.00')->getId();
        $wallets = $this->service(WalletRepository::class);
        $wallet = $wallets->findById($walletId);
        self::assertNotNull($wallet);
        $wallet->reserve(Money::of('40.00', Currency::PLN));
        $wallets->save($wallet);

        $transaction = Transaction::create(
            fromWalletId: $walletId,
            toWalletId: $walletId,
            fromAmount: Money::of('40.00', Currency::PLN),
            toAmount: Money::of('40.00', Currency::PLN),
            spread: Money::zero(Currency::PLN),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::PLN, '1'),
            requiresAntiFraudCheck: false,
        );
        $this->service(TransactionRepository::class)->save($transaction);

        $this->service(TransactionProcessorService::class)->complete($this->reload((int) $transaction->getId()));

        $this->assertWallet($walletId, '100.00', '0.00');
        self::assertSame(TransactionStatus::COMPLETED, $this->reload((int) $transaction->getId())->getStatus());
    }

    private function reload(int $transactionId): Transaction
    {
        $transaction = $this->service(TransactionRepository::class)->findById($transactionId);
        self::assertNotNull($transaction);

        return $transaction;
    }

    private function assertWallet(int $walletId, string $balance, string $reserved): void
    {
        $wallet = $this->service(WalletRepository::class)->findById($walletId);

        self::assertSame($balance, $wallet?->getBalance()->toString(), 'balance');
        self::assertSame($reserved, $wallet?->getReserved()->toString(), 'reserved');
    }

    private function companyBalance(Currency $currency): Money
    {
        return $this->service(CompanyWalletRepository::class)->findByCurrency($currency)?->getBalance() ?? Money::zero($currency);
    }
}
