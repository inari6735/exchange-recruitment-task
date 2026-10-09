<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\ValueObject\Money;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DepositServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private DepositService $depositService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->depositService = new DepositService($this->walletRepository);
    }

    public function testDepositSuccessfully(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::PLN);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with($wallet);

        $result = $this->depositService->deposit($userId, 1, '500.00');

        self::assertSame('500.00', $result->getBalance()->toString());
        self::assertNotNull($result->getLastActivityAt());
    }

    public function testDepositAddsToExistingBalance(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::EUR);
        $wallet->setBalance(Money::of('200.00', Currency::EUR));

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        $this->depositService->deposit($userId, 1, '300.00');

        self::assertSame('500.00', $wallet->getBalance()->toString());
    }

    public function testDepositIsExactForDecimalFractions(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);
        $wallet->setBalance(Money::of('0.10', Currency::PLN));

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

        $this->depositService->deposit(1, 1, '0.20');

        self::assertSame('0.30', $wallet->getBalance()->toString());
    }

    public function testDepositThrowsWhenAmountHasTooManyDecimalPlaces(): void
    {
        $wallet = Wallet::create(1, Currency::JPY);

        $this->walletRepository
            ->method('findById')
            ->willReturn($wallet);

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
        $wallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->depositService->deposit(1, 1, '100.00');
    }

    public function testDepositThrowsWhenWalletIsBlocked(): void
    {
        $userId = 1;
        $wallet = Wallet::create($userId, Currency::PLN);
        $wallet->setIsBlocked(true);

        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);

        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 1 is blocked.');

        $this->depositService->deposit($userId, 1, '100.00');
    }
}
