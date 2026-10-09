<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessTransactionsCommand;
use App\Entity\Transaction;
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
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[AllowMockObjectsWithoutExpectations]
class ProcessTransactionsCommandTest extends TestCase
{
    private TransactionRepositoryInterface $transactionRepository;
    private CommandTester $tester;

    protected function setUp(): void
    {
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->transactionRepository
            ->method('findByStatus')
            ->willReturnMap([
                [TransactionStatus::PENDING, [$this->makePendingTransaction(7), $this->makePendingTransaction(8)]],
                [TransactionStatus::FRAUD_REVIEW, []],
            ]);

        $walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $walletRepository
            ->method('findById')
            ->willReturnCallback(static fn (int $id) => 1 === $id
                ? WalletFixture::create(1, 1, Currency::PLN, '500.00', '200.00')
                : WalletFixture::create(2, 1, Currency::EUR));

        $processor = new TransactionProcessorService(
            $walletRepository,
            $this->transactionRepository,
            $this->createMock(CompanyWalletRepositoryInterface::class),
            new ImmediateTransactionManager(),
        );

        $this->tester = new CommandTester(new ProcessTransactionsCommand($this->transactionRepository, $processor));
    }

    public function testCompletesPendingTransactions(): void
    {
        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Transaction #7 completed.', $this->tester->getDisplay());
        self::assertStringContainsString('Transaction #8 completed.', $this->tester->getDisplay());
    }

    public function testSkipsTransactionAlreadyProcessedElsewhereAndContinues(): void
    {
        $this->transactionRepository
            ->method('save')
            ->willReturnCallback(static function (Transaction $transaction): void {
                if (7 === $transaction->getId()) {
                    throw new TransactionAlreadyProcessedException(7);
                }
            });

        $exitCode = $this->tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Transaction #7 was already processed, skipped.', $this->tester->getDisplay());
        self::assertStringContainsString('Transaction #8 completed.', $this->tester->getDisplay());
    }

    public function testSkipsTransactionWhoseReservationWasAlreadyConsumedByOtherRun(): void
    {
        $repository = $this->createMock(TransactionRepositoryInterface::class);
        $repository
            ->method('findByStatus')
            ->willReturnMap([
                [TransactionStatus::PENDING, [$this->makePendingTransaction(7, 3), $this->makePendingTransaction(8)]],
                [TransactionStatus::FRAUD_REVIEW, []],
            ]);
        $repository
            ->method('save')
            ->willReturnCallback(static function (Transaction $transaction): void {
                if (7 === $transaction->getId()) {
                    throw new TransactionAlreadyProcessedException(7);
                }
            });

        $walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $walletRepository
            ->method('findById')
            ->willReturnCallback(static fn (int $id) => match ($id) {
                1 => WalletFixture::create(1, 1, Currency::PLN, '500.00', '200.00'),
                3 => WalletFixture::create(3, 1, Currency::PLN, '500.00', '0.00'),
                default => WalletFixture::create(2, 1, Currency::EUR),
            });

        $processor = new TransactionProcessorService(
            $walletRepository,
            $repository,
            $this->createMock(CompanyWalletRepositoryInterface::class),
            new ImmediateTransactionManager(),
        );
        $tester = new CommandTester(new ProcessTransactionsCommand($repository, $processor));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Transaction #7 was already processed, skipped.', $tester->getDisplay());
        self::assertStringContainsString('Transaction #8 completed.', $tester->getDisplay());
    }

    private function makePendingTransaction(int $id, int $fromWalletId = 1): Transaction
    {
        return new Transaction(
            id: $id,
            fromWalletId: $fromWalletId,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('23.43', Currency::EUR),
            spread: Money::of('0.16', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.235910'),
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
}
