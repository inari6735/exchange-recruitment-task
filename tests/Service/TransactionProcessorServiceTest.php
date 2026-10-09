<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\TransactionAlreadyProcessedException;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\TransactionProcessorService;
use App\Tests\Support\ImmediateTransactionManager;
use App\Tests\Support\WalletFixture;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransactionProcessorServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private CompanyWalletRepositoryInterface $companyWalletRepository;
    private ImmediateTransactionManager $transactionManager;
    private TransactionProcessorService $transactionProcessorService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);
        $this->transactionManager = new ImmediateTransactionManager();

        $this->transactionProcessorService = new TransactionProcessorService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->companyWalletRepository,
            $this->transactionManager,
        );
    }

    public function testCompleteSettlesReservationCreditsTargetAndBooksSpread(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00');
        $toWallet = WalletFixture::create(2, 1, Currency::EUR, '100.00');
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);
        $this->givenWallets($fromWallet, $toWallet);

        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(1, 2);
        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));
        $this->companyWalletRepository
            ->expects(self::once())
            ->method('addToBalance')
            ->with($this->callback(static fn (Money $spread): bool => Currency::EUR === $spread->getCurrency() && '0.50' === $spread->toString()));
        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->transactionProcessorService->complete($transaction);

        self::assertSame('400.00', $fromWallet->getBalance()->toString());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
        self::assertSame('125.00', $toWallet->getBalance()->toString());
        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNotNull($toWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
        self::assertSame(1, $this->transactionManager->calls);
    }

    public function testCompleteOfSameWalletTransactionSettlesOnce(): void
    {
        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturnCallback(static fn (): Wallet => WalletFixture::create(1, 1, Currency::PLN, '100.00', '40.00'));
        $saved = [];
        $this->walletRepository
            ->method('save')
            ->willReturnCallback(static function (Wallet $wallet) use (&$saved): void {
                $saved[] = $wallet;
            });
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');
        $transaction = Transaction::create(
            fromWalletId: 1,
            toWalletId: 1,
            fromAmount: Money::of('40.00', Currency::PLN),
            toAmount: Money::of('40.00', Currency::PLN),
            spread: Money::zero(Currency::PLN),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::PLN, '1'),
            requiresAntiFraudCheck: false,
        );

        $this->transactionProcessorService->complete($transaction);

        self::assertCount(1, $saved);
        self::assertSame('100.00', $saved[0]->getBalance()->toString());
        self::assertSame('0.00', $saved[0]->getReserved()->toString());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testCompleteSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00'), WalletFixture::create(2, 1, Currency::EUR));
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteDoesNotBookZeroSpread(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::USD, '50.00', '50.00'), WalletFixture::create(2, 1, Currency::USD));
        $transaction = Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('50.00', Currency::USD),
            toAmount: Money::of('50.00', Currency::USD),
            spread: Money::zero(Currency::USD),
            exchangeRate: ExchangeRate::of(Currency::USD, Currency::USD, '1'),
            requiresAntiFraudCheck: false,
        );

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testCompleteRejectsAndReleasesWhenSourceWalletBlocked(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00', blocked: true);
        $toWallet = WalletFixture::create(2, 1, Currency::EUR, '100.00');
        $this->givenWallets($fromWallet, $toWallet);
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
        self::assertSame('100.00', $toWallet->getBalance()->toString());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteRejectsAndReleasesWhenTargetWalletBlocked(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00');
        $toWallet = WalletFixture::create(2, 1, Currency::EUR, '100.00', blocked: true);
        $this->givenWallets($fromWallet, $toWallet);
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
        self::assertSame('100.00', $toWallet->getBalance()->toString());
    }

    public function testCompleteRejectsWhenFromWalletNotFound(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, null],
                [2, WalletFixture::create(2, 1, Currency::EUR)],
            ]);

        $this->walletRepository->expects(self::never())->method('save');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testCompleteRejectsAndReleasesWhenToWalletNotFound(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00');
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, null],
            ]);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::identicalTo($fromWallet));

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
    }

    public function testCompletePropagatesAlreadyProcessed(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00'), WalletFixture::create(2, 1, Currency::EUR));
        $this->transactionRepository
            ->method('save')
            ->willThrowException(new TransactionAlreadyProcessedException(7));

        $this->expectException(TransactionAlreadyProcessedException::class);

        $this->transactionProcessorService->complete($this->makeTransaction(requiresAntiFraudCheck: false));
    }

    public function testCompleteOfAlreadyProcessedTransactionFailsBeforeTouchingWallets(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '500.00', '0.00'), WalletFixture::create(2, 1, Currency::EUR));
        $this->transactionRepository
            ->method('save')
            ->willThrowException(new TransactionAlreadyProcessedException(7));
        $this->walletRepository->expects(self::never())->method('save');
        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->expectException(TransactionAlreadyProcessedException::class);

        $this->transactionProcessorService->complete($this->makeTransaction(requiresAntiFraudCheck: false));
    }

    public function testRejectOfAlreadyProcessedTransactionFailsBeforeReleasing(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturn(WalletFixture::create(1, 1, Currency::PLN, '500.00', '0.00'));
        $this->transactionRepository
            ->method('save')
            ->willThrowException(new TransactionAlreadyProcessedException(7));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(TransactionAlreadyProcessedException::class);

        $this->transactionProcessorService->reject($this->makeTransaction(requiresAntiFraudCheck: false));
    }

    public function testRejectSetsRejectedStatus(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $wallet = $this->createMock(Wallet::class);
        $wallet
            ->expects($this->once())
            ->method('release')
            ->with($this->callback(static fn (Money $amount): bool => Currency::PLN === $amount->getCurrency() && '100.00' === $amount->toString()));

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

    public function testRejectLocksSourceWallet(): void
    {
        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(1);

        $this->transactionProcessorService->reject($this->makeTransaction(requiresAntiFraudCheck: false));

        self::assertSame(1, $this->transactionManager->calls);
    }

    private function givenWallets(Wallet $fromWallet, Wallet $toWallet): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);
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
