<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
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
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);

        $this->transferService = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->exchangeRateService,
            $this->spreadService,
        );
    }

    public function testTransferSuccessfully(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet
            ->method('getCurrency')
            ->willReturn(Currency::PLN);
        $fromWallet
            ->expects($this->atLeastOnce())
            ->method('getBalance')
            ->willReturnOnConsecutiveCalls(
                Money::of('5000.00', Currency::PLN),
                Money::of('4000.00', Currency::PLN),
            );
        $fromWallet
            ->method('getUserId')
            ->willReturn($userId);
        $fromWallet
            ->expects($this->never())
            ->method('setBalance');
        $toWallet = $this->createMock(Wallet::class);
        $toWallet
            ->method('getCurrency')
            ->willReturn(Currency::EUR);
        $toWallet
            ->expects($this->atLeastOnce())
            ->method('getBalance')
            ->willReturnOnConsecutiveCalls(
                Money::of('100.00', Currency::EUR),
                Money::of('349.00', Currency::EUR),
            );
        $toWallet
            ->method('getUserId')
            ->willReturn($userId);
        $toWallet
            ->expects($this->never())
            ->method('setBalance');

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
            ->expects(self::exactly(2))
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame('4000.00', $fromWallet->getBalance()->toString());
        self::assertSame('349.00', $toWallet->getBalance()->toString());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.00', $transaction->getFromAmount()->toString());
        self::assertSame('249.00', $transaction->getToAmount()->toString());
        self::assertSame('0.250000', $transaction->getExchangeRate()->toString());
        self::assertSame('1.00', $transaction->getSpread()->toString());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
    }

    #[DataProvider('referenceTransferProvider')]
    public function testTransferCalculatesAmountsWithRealRatesAndSpread(
        Currency $toCurrency,
        string $expectedRate,
        string $expectedSpread,
        string $expectedToAmount,
    ): void {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(Money::of('500.00', Currency::PLN));
        $toWallet = Wallet::create(1, $toCurrency);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $transaction = $this->makeServiceWithRealRates()->transfer(1, 1, 2, '100.00');

        self::assertSame('100.00', $transaction->getFromAmount()->toString());
        self::assertSame($expectedRate, $transaction->getExchangeRate()->toString());
        self::assertSame($expectedSpread, $transaction->getSpread()->toString());
        self::assertSame($expectedToAmount, $transaction->getToAmount()->toString());
        self::assertSame($toCurrency, $transaction->getToCurrency());
        self::assertSame('400.00', $fromWallet->getBalance()->toString());
        self::assertSame($expectedToAmount, $toWallet->getBalance()->toString());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
    }

    public static function referenceTransferProvider(): Generator
    {
        // 100 PLN × rate → gross (rounded to target scale) − spread = net
        yield 'PLN to HUF' => [Currency::HUF, '84.745763', '89.21', '8385.37'];
        yield 'PLN to JPY' => [Currency::JPY, '43.668122', '34', '4333'];
        yield 'PLN to EUR' => [Currency::EUR, '0.235910', '0.16', '23.43'];
    }

    public function testTransferFlagsAntiFraudCheckWhenToAmountExceedsThreshold(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $toWallet = Wallet::create(1, Currency::HUF);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        // 200 PLN → 16949.15 HUF − 178.41 spread = 16770.74 HUF > 15000
        $transaction = $this->makeServiceWithRealRates()->transfer(1, 1, 2, '200.00');

        self::assertSame('16770.74', $transaction->getToAmount()->toString());
        self::assertTrue($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
    }

    public function testTransferThrowsWhenAmountHasTooManyDecimalPlaces(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, Wallet::create(1, Currency::PLN)],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for PLN.');

        $this->transferService->transfer(1, 1, 2, '10.001');
    }

    public function testTransferThrowsWhenAmountHasDecimalsForJpyWallet(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, Wallet::create(1, Currency::JPY)],
                [2, Wallet::create(1, Currency::PLN)],
            ]);

        $this->walletRepository->expects(self::never())->method('save');
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
        $fromWallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [99, null],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 1, 99, '100.00');
    }

    public function testTransferThrowsWhenFromWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($fromWallet);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $toWallet = Wallet::create(2, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    private function makeServiceWithRealRates(): TransferService
    {
        return new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            new ExchangeRateService(),
            new SpreadService(),
        );
    }
}
