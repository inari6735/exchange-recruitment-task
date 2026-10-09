<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransfersException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\WalletService;
use App\Tests\Support\ImmediateTransactionManager;
use App\Tests\Support\WalletFixture;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

#[AllowMockObjectsWithoutExpectations]
class WalletServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private ImmediateTransactionManager $transactionManager;
    private WalletService $walletService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->transactionManager = new ImmediateTransactionManager();
        $this->walletService = new WalletService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->transactionManager,
        );
    }

    public function testCreateWalletSuccessfully(): void
    {
        $userId = 1;
        $currency = Currency::EUR;

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with($userId, $currency)
            ->willReturn(null);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));

        $wallet = $this->walletService->createWallet($userId, $currency);

        self::assertSame($userId, $wallet->getUserId());
        self::assertSame($currency, $wallet->getCurrency());
        self::assertSame('0.00', $wallet->getBalance()->toString());
        self::assertFalse($wallet->isBlocked());
    }

    public function testCreateWalletThrowsWhenWalletAlreadyExists(): void
    {
        $userId = 1;
        $currency = Currency::PLN;

        $existingWallet = Wallet::create($userId, $currency);

        $this->walletRepository
            ->expects(self::once())
            ->method('findByUserIdAndCurrency')
            ->with($userId, $currency)
            ->willReturn($existingWallet);

        $this->walletRepository
            ->expects(self::never())
            ->method('save');

        $this->expectException(WalletAlreadyExistsException::class);
        $this->expectExceptionMessage('Wallet for user 1 in currency PLN already exists.');

        $this->walletService->createWallet($userId, $currency);
    }

    public function testCloseWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN);

        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(7);
        $this->walletRepository->expects(self::once())->method('findById')->with(7)->willReturn($wallet);
        $this->transactionRepository->expects(self::once())->method('hasInFlightTransfers')->with(7)->willReturn(false);
        $this->walletRepository->expects(self::once())->method('save')->with(self::identicalTo($wallet));

        $this->walletService->closeWallet(1, 7);

        self::assertTrue($wallet->isClosed());
        self::assertSame(1, $this->transactionManager->calls);
    }

    public function testCloseLocksWalletBeforeReadingIt(): void
    {
        $calls = [];
        $this->walletRepository
            ->method('lockForUpdate')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'lock';
            });
        $this->walletRepository
            ->method('findById')
            ->willReturnCallback(static function () use (&$calls): Wallet {
                $calls[] = 'find';

                return WalletFixture::create(7, 1, Currency::PLN);
            });

        $this->walletService->closeWallet(1, 7);

        self::assertSame(['lock', 'find'], $calls);
    }

    #[DataProvider('notFoundProvider')]
    public function testCloseThrowsNotFound(?Wallet $wallet): void
    {
        $this->walletRepository->method('findById')->willReturn($wallet);
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 7 not found.');

        $this->walletService->closeWallet(1, 7);
    }

    public static function notFoundProvider(): Generator
    {
        yield 'missing' => [null];
        yield 'other user' => [WalletFixture::create(7, 2, Currency::PLN)];
        yield 'already closed' => [WalletFixture::create(7, 1, Currency::PLN, closed: true)];
    }

    /**
     * @param class-string<Throwable> $exception
     */
    #[DataProvider('refusalProvider')]
    public function testCloseRefusals(Wallet $wallet, bool $inFlight, string $exception, string $message): void
    {
        $this->walletRepository->method('findById')->willReturn($wallet);
        $this->transactionRepository->method('hasInFlightTransfers')->willReturn($inFlight);
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        $this->walletService->closeWallet(1, 7);
    }

    public static function refusalProvider(): Generator
    {
        yield 'blocked beats non-zero balance' => [
            WalletFixture::create(7, 1, Currency::PLN, '10.00', blocked: true), true,
            WalletBlockedException::class, 'Wallet 7 is blocked.',
        ];
        yield 'non-zero balance beats pending transfers' => [
            WalletFixture::create(7, 1, Currency::PLN, '10.00'), true,
            WalletNotEmptyException::class, 'Wallet 7 has a non-zero balance.',
        ];
        yield 'legacy negative balance' => [
            WalletFixture::create(7, 1, Currency::PLN, '-995.00'), false,
            WalletNotEmptyException::class, 'Wallet 7 has a non-zero balance.',
        ];
        yield 'pending transfers' => [
            WalletFixture::create(7, 1, Currency::PLN), true,
            WalletHasPendingTransfersException::class, 'Wallet 7 has pending transfers.',
        ];
    }

    public function testCloseRejectsNegativeLegacyBalance(): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(7, 1, Currency::PLN, '-995.00'));
        $this->transactionRepository->expects(self::never())->method('hasInFlightTransfers');

        $this->expectException(WalletNotEmptyException::class);

        $this->walletService->closeWallet(1, 7);
    }
}
