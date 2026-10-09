<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\WalletResponse;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Tests\Support\WalletFixture;
use App\ValueObject\Money;
use DateTimeImmutable;
use DateTimeInterface;
use PHPUnit\Framework\TestCase;

class WalletResponseTest extends TestCase
{
    public function testJsonSerializeWithoutLastActivityAt(): void
    {
        $wallet = Wallet::create(1, Currency::PLN);

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertNull($data['id']);
        self::assertSame('PLN', $data['currency']);
        self::assertSame('0.00', $data['balance']);
        self::assertSame('0.00', $data['reserved']);
        self::assertSame('0.00', $data['available']);
        self::assertFalse($data['isBlocked']);
        self::assertNull($data['lastActivityAt']);
    }

    public function testJsonSerializeWithLastActivityAt(): void
    {
        $lastActivity = new DateTimeImmutable('2024-06-01T12:00:00+00:00');
        $wallet = Wallet::create(1, Currency::EUR);
        $wallet->setLastActivityAt($lastActivity);

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertSame($lastActivity->format(DateTimeInterface::ATOM), $data['lastActivityAt']);
    }

    public function testJsonSerializeWithBlockedWallet(): void
    {
        $wallet = Wallet::create(1, Currency::USD);
        $wallet->setIsBlocked(true);

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertTrue($data['isBlocked']);
    }

    public function testJsonSerializeBalanceAsStringInCurrencyScale(): void
    {
        $wallet = Wallet::create(1, Currency::JPY);
        $wallet->setBalance(Money::of('1250', Currency::JPY));

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertSame('1250', $data['balance']);
    }

    public function testJsonSerializeReservedAndAvailable(): void
    {
        $wallet = WalletFixture::create(3, 1, Currency::PLN, '100.00', '30.00');

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertSame('100.00', $data['balance']);
        self::assertSame('30.00', $data['reserved']);
        self::assertSame('70.00', $data['available']);
    }
}
