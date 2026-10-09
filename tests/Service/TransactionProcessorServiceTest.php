<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransactionProcessorServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private TransactionProcessorService $transactionProcessorService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);

        $this->transactionProcessorService = new TransactionProcessorService(
            $this->walletRepository,
            $this->transactionRepository,
        );
    }

    public function testCompleteUpdatesWalletBalancesAndSetsCompletedStatus(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(Money::of('500.00', Currency::PLN));

        $toWallet = Wallet::create(1, Currency::EUR);
        $toWallet->setBalance(Money::of('100.00', Currency::EUR));

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->transactionProcessorService->complete($transaction);

        self::assertSame('400.00', $fromWallet->getBalance()->toString());
        self::assertSame('125.00', $toWallet->getBalance()->toString());
        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNotNull($toWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(Money::of('500.00', Currency::PLN));

        $toWallet = Wallet::create(1, Currency::EUR);

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteRejectsWhenFromWalletNotFound(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, null],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->walletRepository->expects(self::never())->method('save');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testCompleteRejectsWhenToWalletNotFound(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(Money::of('500.00', Currency::PLN));

        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, null],
            ]);

        $this->walletRepository->expects(self::never())->method('save');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testRejectSetsRejectedStatus(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $wallet = $this->createStub(Wallet::class);

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);
        $this->walletRepository
            ->expects($this->once())
            ->method('save')
            ->with($wallet);

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRejectSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    private function makeTransaction(bool $requiresAntiFraudCheck): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('25.00', Currency::EUR),
            spread: Money::of('0.50', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
    }
}
