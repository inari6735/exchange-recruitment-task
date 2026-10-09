<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use App\Tests\Support\ImmediateTransactionManager;
use App\Tests\Support\TransactionLimitsFactory;
use App\Tests\Support\WalletFixture;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransferServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private ExchangeRateService $exchangeRateService;
    private SpreadService $spreadService;
    private ImmediateTransactionManager $transactionManager;
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);
        $this->transactionManager = new ImmediateTransactionManager();

        $this->transferService = $this->makeService($this->exchangeRateService, $this->spreadService);
    }

    /**
     * Intent kept from the original test: placing a transfer does not change any balance — it only reserves funds.
     */
    public function testTransferSuccessfully(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::PLN);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet
            ->expects($this->once())
            ->method('reserve')
            ->with($this->callback(static fn (Money $amount): bool => Currency::PLN === $amount->getCurrency() && '1000.00' === $amount->toString()));
        $fromWallet->expects($this->never())->method('settle');
        $fromWallet->expects($this->never())->method('credit');
        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::EUR);
        $toWallet->method('getUserId')->willReturn($userId);
        $toWallet->expects($this->never())->method('credit');
        $toWallet->expects($this->never())->method('reserve');

        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(1, 2);
        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->exchangeRateService
            ->expects(self::once())
            ->method('getExchangeRateBetween')
            ->with(Currency::PLN, Currency::EUR)
            ->willReturn(ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'));

        $this->spreadService
            ->expects(self::once())
            ->method('calculateSpread')
            ->with(
                $this->callback(static fn (Money $price): bool => Currency::EUR === $price->getCurrency() && '250.00' === $price->toString()),
                Currency::PLN,
                Currency::EUR,
            )
            ->willReturn(Money::of('1.00', Currency::EUR));

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::identicalTo($fromWallet));

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.00', $transaction->getFromAmount()->toString());
        self::assertSame('249.00', $transaction->getToAmount()->toString());
        self::assertSame('0.250000', $transaction->getExchangeRate()->toString());
        self::assertSame('1.00', $transaction->getSpread()->toString());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
        self::assertSame(1, $this->transactionManager->calls);
    }

    public function testTransferLocksWalletsBeforeReadingThem(): void
    {
        $calls = [];
        $wallets = [1 => WalletFixture::create(1, 1, Currency::PLN, '100.00'), 2 => WalletFixture::create(2, 1, Currency::EUR)];

        $this->walletRepository
            ->method('lockForUpdate')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'lock';
            });
        $this->walletRepository
            ->method('findById')
            ->willReturnCallback(static function (int $id) use (&$calls, $wallets): ?Wallet {
                $calls[] = 'find';

                return $wallets[$id] ?? null;
            });

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '10.00');

        self::assertSame(['lock', 'find', 'find'], $calls);
    }

    #[DataProvider('referenceTransferProvider')]
    public function testTransferReservesFundsAndCalculatesAmounts(
        Currency $toCurrency,
        string $expectedRate,
        string $expectedSpread,
        string $expectedToAmount,
    ): void {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00');
        $toWallet = WalletFixture::create(2, 1, $toCurrency);
        $this->givenWallets($fromWallet, $toWallet);

        $transaction = $this->makeServiceWithRealRates()->transfer(1, 1, 2, '100.00');

        self::assertSame('100.00', $transaction->getFromAmount()->toString());
        self::assertSame($expectedRate, $transaction->getExchangeRate()->toString());
        self::assertSame($expectedSpread, $transaction->getSpread()->toString());
        self::assertSame($expectedToAmount, $transaction->getToAmount()->toString());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
        self::assertSame('100.00', $fromWallet->getReserved()->toString());
        self::assertSame('400.00', $fromWallet->getAvailable()->toString());
        self::assertSame(Money::zero($toCurrency)->toString(), $toWallet->getBalance()->toString());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
    }

    public static function referenceTransferProvider(): Generator
    {
        // 100 PLN × rate → gross (rounded to target scale) − spread = net
        yield 'PLN to HUF' => [Currency::HUF, '84.745763', '89.21', '8385.37'];
        yield 'PLN to JPY' => [Currency::JPY, '43.668122', '34', '4333'];
        yield 'PLN to EUR' => [Currency::EUR, '0.235910', '0.16', '23.43'];
    }

    #[DataProvider('antiFraudProvider')]
    public function testAntiFraudThresholdUsesSourceCurrency(Currency $from, Currency $to, string $amount, bool $expectedReview): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, $from, '2000000'), WalletFixture::create(2, 1, $to));

        $transaction = $this->makeServiceWithRealRates()->transfer(1, 1, 2, $amount);

        self::assertSame($expectedReview, $transaction->requiresAntiFraudCheck());
        self::assertSame(
            $expectedReview ? TransactionStatus::FRAUD_REVIEW : TransactionStatus::PENDING,
            $transaction->getStatus(),
        );
    }

    public static function antiFraudProvider(): Generator
    {
        yield 'PLN exactly at threshold' => [Currency::PLN, Currency::EUR, '15000.00', false];
        yield 'PLN just above threshold' => [Currency::PLN, Currency::EUR, '15000.01', true];
        yield 'USD just above its own threshold' => [Currency::USD, Currency::PLN, '4000.01', true];
        yield 'JPY just above its own threshold' => [Currency::JPY, Currency::PLN, '650001', true];
        // Old rule compared the target amount (16770.74 HUF > 15000) — the source amount 200 PLN is now below the PLN threshold.
        yield 'PLN to HUF large target amount' => [Currency::PLN, Currency::HUF, '200.00', false];
    }

    public function testTransferOfExactlyAvailableAmountSucceeds(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '100.00', '30.00');
        $this->givenWallets($fromWallet, WalletFixture::create(2, 1, Currency::EUR));

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '70.00');

        self::assertSame('100.00', $fromWallet->getReserved()->toString());
        self::assertSame('0.00', $fromWallet->getAvailable()->toString());
    }

    public function testTransferThrowsWhenFundsAreInsufficient(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00', '30.00'), WalletFixture::create(2, 1, Currency::EUR));
        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessage('Insufficient funds in wallet 1.');

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '70.01');
    }

    public function testTransferFailsFromLegacyNegativeBalance(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '-995.00'), WalletFixture::create(2, 1, Currency::EUR));

        $this->expectException(InsufficientFundsException::class);

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '1.00');
    }

    public function testTransferThrowsForSameWallet(): void
    {
        $this->walletRepository->expects(self::never())->method('findById');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(SameWalletTransferException::class);
        $this->expectExceptionMessage('Cannot transfer to the same wallet.');

        $this->transferService->transfer(1, 1, 1, '10.00');
    }

    public function testTransferThrowsWhenSourceWalletIsBlocked(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00', blocked: true), WalletFixture::create(2, 1, Currency::EUR));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 1 is blocked.');

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '10.00');
    }

    public function testTransferThrowsWhenTargetWalletIsBlocked(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '100.00');
        $this->givenWallets($fromWallet, WalletFixture::create(2, 1, Currency::EUR, blocked: true));
        $this->transactionRepository->expects(self::never())->method('save');

        try {
            $this->makeServiceWithRealRates()->transfer(1, 1, 2, '10.00');
            self::fail('Expected WalletBlockedException.');
        } catch (WalletBlockedException $e) {
            self::assertSame('Wallet 2 is blocked.', $e->getMessage());
        }

        self::assertSame('0.00', $fromWallet->getReserved()->toString());
    }

    public function testTransferThrowsWhenAmountHasTooManyDecimalPlaces(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00'), WalletFixture::create(2, 1, Currency::EUR));
        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for PLN.');

        $this->transferService->transfer(1, 1, 2, '10.001');
    }

    public function testTransferThrowsWhenAmountHasDecimalsForJpyWallet(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::JPY, '1000'), WalletFixture::create(2, 1, Currency::PLN));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for JPY.');

        $this->transferService->transfer(1, 1, 2, '100.5');
    }

    public function testTransferThrowsWhenFromWalletNotFound(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 99, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletNotFound(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, WalletFixture::create(1, 1, Currency::PLN, '100.00')],
                [99, null],
            ]);
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 1, 99, '100.00');
    }

    public function testTransferThrowsWhenFromWalletBelongsToOtherUser(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(WalletFixture::create(1, 2, Currency::PLN, '100.00'));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletBelongsToOtherUser(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00'), WalletFixture::create(2, 2, Currency::EUR));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    private function givenWallets(Wallet $fromWallet, Wallet $toWallet): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [(int) $fromWallet->getId(), $fromWallet],
                [(int) $toWallet->getId(), $toWallet],
            ]);
    }

    private function makeServiceWithRealRates(): TransferService
    {
        return $this->makeService(new ExchangeRateService(), new SpreadService());
    }

    private function makeService(ExchangeRateService $exchangeRateService, SpreadService $spreadService): TransferService
    {
        return new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $exchangeRateService,
            $spreadService,
            TransactionLimitsFactory::default(),
            $this->transactionManager,
        );
    }
}
