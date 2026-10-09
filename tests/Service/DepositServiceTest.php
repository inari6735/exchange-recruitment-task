<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Exception\DepositLimitExceededException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Tests\Support\ImmediateTransactionManager;
use App\Tests\Support\TransactionLimitsFactory;
use App\Tests\Support\WalletFixture;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DepositServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private ImmediateTransactionManager $transactionManager;
    private DepositService $depositService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionManager = new ImmediateTransactionManager();
        $this->depositService = new DepositService(
            $this->walletRepository,
            TransactionLimitsFactory::default(),
            $this->transactionManager,
        );
    }

    public function testDepositSuccessfully(): void
    {
        $wallet = WalletFixture::create(1, 1, Currency::PLN);

        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(1);
        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);
        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::identicalTo($wallet));

        $result = $this->depositService->deposit(1, 1, '500.00');

        self::assertSame('500.00', $result->getBalance()->toString());
        self::assertNotNull($result->getLastActivityAt());
        self::assertSame(1, $this->transactionManager->calls);
    }

    public function testDepositAddsToExistingBalance(): void
    {
        $wallet = WalletFixture::create(1, 1, Currency::EUR, '200.00');
        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->depositService->deposit(1, 1, '300.00');

        self::assertSame('500.00', $wallet->getBalance()->toString());
    }

    public function testDepositIsExactForDecimalFractions(): void
    {
        $wallet = WalletFixture::create(1, 1, Currency::PLN, '0.10');
        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->depositService->deposit(1, 1, '0.20');

        self::assertSame('0.30', $wallet->getBalance()->toString());
    }

    #[DataProvider('limitProvider')]
    public function testDepositAcceptsExactlyTheCurrencyLimit(Currency $currency, string $limit): void
    {
        $wallet = WalletFixture::create(1, 1, $currency);
        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->depositService->deposit(1, 1, $limit);

        self::assertSame($limit, $wallet->getBalance()->toString());
    }

    public static function limitProvider(): Generator
    {
        yield 'PLN' => [Currency::PLN, '10000.00'];
        yield 'EUR' => [Currency::EUR, '2500.00'];
        yield 'JPY' => [Currency::JPY, '450000'];
    }

    #[DataProvider('overLimitProvider')]
    public function testDepositThrowsWhenAmountExceedsCurrencyLimit(Currency $currency, string $amount, string $message): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 1, $currency));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(DepositLimitExceededException::class);
        $this->expectExceptionMessage($message);

        $this->depositService->deposit(1, 1, $amount);
    }

    public static function overLimitProvider(): Generator
    {
        yield 'PLN' => [Currency::PLN, '10000.01', 'Amount cannot exceed 10000.00 PLN.'];
        yield 'EUR' => [Currency::EUR, '2500.01', 'Amount cannot exceed 2500.00 EUR.'];
        yield 'JPY' => [Currency::JPY, '450001', 'Amount cannot exceed 450000 JPY.'];
    }

    public function testDepositThrowsWhenAmountHasTooManyDecimalPlaces(): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 1, Currency::JPY));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for JPY.');

        $this->depositService->deposit(1, 1, '100.50');
    }

    public function testDepositThrowsWhenWalletNotFound(): void
    {
        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->depositService->deposit(1, 99, '100.00');
    }

    public function testDepositThrowsWhenWalletBelongsToOtherUser(): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 2, Currency::PLN));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->depositService->deposit(1, 1, '100.00');
    }

    public function testDepositThrowsWhenWalletIsBlocked(): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 1, Currency::PLN, blocked: true));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 1 is blocked.');

        $this->depositService->deposit(1, 1, '100.00');
    }

    public function testDepositThrowsWhenWalletIsClosed(): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 1, Currency::PLN, closed: true));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->depositService->deposit(1, 1, '100.00');
    }
}
