<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Currency;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Freezes the API contract that the controller refactor must keep byte-for-byte (status + body).
 */
class WalletApiContractTest extends ApiTestCase
{
    // ---- POST /api/wallets ----

    public function testCreateWallet(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets', $token, ['currency' => 'PLN']);

        self::assertSame(201, $response['status']);
        self::assertSame('PLN', $response['body']['currency']);
        self::assertSame('0.00', $response['body']['balance']);
        self::assertSame('0.00', $response['body']['reserved']);
        self::assertSame('0.00', $response['body']['available']);
    }

    public function testCreateWalletIgnoresUnknownFields(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets', $token, ['currency' => 'PLN', 'note' => 'ignored']);

        self::assertSame(201, $response['status']);
    }

    #[DataProvider('missingCurrencyProvider')]
    public function testCreateWalletWithoutCurrency(string $rawBody): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets', $token, rawBody: $rawBody);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Missing required field: currency.'], $response['body']);
    }

    public static function missingCurrencyProvider(): Generator
    {
        yield 'empty object' => ['{}'];
        yield 'null' => ['{"currency": null}'];
        yield 'empty json array' => ['[]'];
    }

    #[DataProvider('invalidCurrencyProvider')]
    public function testCreateWalletWithInvalidCurrency(mixed $currency): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets', $token, ['currency' => $currency]);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Invalid currency.'], $response['body']);
    }

    public static function invalidCurrencyProvider(): Generator
    {
        yield 'unknown code' => ['XYZ'];
        yield 'lowercase' => ['pln'];
        yield 'empty string' => [''];
    }

    public function testCreateWalletConflict(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $this->createWallet($user, Currency::PLN);

        $response = $this->sendJson('POST', '/api/wallets', $token, ['currency' => 'PLN']);

        self::assertSame(409, $response['status']);
        // The message must not expose the internal user id.
        self::assertSame(['error' => 'Wallet in currency PLN already exists.'], $response['body']);
    }

    // ---- GET /api/wallets ----

    public function testListWallets(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $this->createWallet($user, Currency::PLN, '12.50');

        $response = $this->sendJson('GET', '/api/wallets', $token, contentType: null);

        self::assertSame(200, $response['status']);
        self::assertCount(1, $response['body']);
        self::assertSame('12.50', $response['body'][0]['balance']);
    }

    // ---- POST /api/wallets/transfer ----

    public function testTransfer(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00');
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $eur->getId(),
            'amount' => '100.00',
        ]);

        self::assertSame(201, $response['status']);
        self::assertSame('100.00', $response['body']['fromAmount']);
        self::assertSame('23.43', $response['body']['toAmount']);
        self::assertSame('pending', $response['body']['status']);
    }

    public function testTransferAcceptsAmountAsJsonNumber(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00');
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $eur->getId(),
            'amount' => 100.5,
        ]);

        self::assertSame(201, $response['status']);
        self::assertSame('100.50', $response['body']['fromAmount']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[DataProvider('missingFieldProvider')]
    public function testTransferWithMissingField(array $payload, string $field): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, $payload);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => sprintf('Missing required field: %s.', $field)], $response['body']);
    }

    public static function missingFieldProvider(): Generator
    {
        yield 'everything missing' => [[], 'fromWalletId'];
        yield 'fromWalletId missing' => [['toWalletId' => 2, 'amount' => '10'], 'fromWalletId'];
        yield 'toWalletId missing' => [['fromWalletId' => 1, 'amount' => '10'], 'toWalletId'];
        yield 'amount missing' => [['fromWalletId' => 1, 'toWalletId' => 2], 'amount'];
        yield 'amount null' => [['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => null], 'amount'];
        yield 'missing beats invalid amount' => [['fromWalletId' => 1, 'amount' => '-5'], 'toWalletId'];
    }

    #[DataProvider('invalidAmountProvider')]
    public function testTransferWithInvalidAmount(mixed $amount): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00');
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $eur->getId(),
            'amount' => $amount,
        ]);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Amount must be a positive number.'], $response['body']);
    }

    public static function invalidAmountProvider(): Generator
    {
        yield 'negative' => ['-50'];
        yield 'zero' => ['0.00'];
        yield 'zero number' => [0];
        yield 'letters' => ['abc'];
        yield 'exponent' => ['1e3'];
        yield 'leading space' => [' 100'];
        yield 'boolean' => [true];
        yield 'array' => [['100']];
    }

    public function testTransferWithTooPreciseAmount(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00');
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $eur->getId(),
            'amount' => '10.001',
        ]);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Amount has too many decimal places for PLN.'], $response['body']);
    }

    public function testTransferToSameWallet(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00');

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $pln->getId(),
            'amount' => '10.00',
        ]);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Cannot transfer to the same wallet.'], $response['body']);
    }

    public function testTransferFromUnknownWallet(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => 999999999,
            'toWalletId' => $eur->getId(),
            'amount' => '10.00',
        ]);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => 'Wallet 999999999 not found.'], $response['body']);
    }

    public function testTransferToAnotherUsersWallet(): void
    {
        [$user, $token] = $this->createUserWithToken();
        [$otherUser] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00');
        $foreign = $this->createWallet($otherUser, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $foreign->getId(),
            'amount' => '10.00',
        ]);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => sprintf('Wallet %d not found.', $foreign->getId())], $response['body']);
    }

    public function testTransferFromBlockedWallet(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00', blocked: true);
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $eur->getId(),
            'amount' => '10.00',
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => sprintf('Wallet %d is blocked.', $pln->getId())], $response['body']);
    }

    public function testTransferWithInsufficientFunds(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '5.00');
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $eur->getId(),
            'amount' => '10.00',
        ]);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => sprintf('Insufficient funds in wallet %d.', $pln->getId())], $response['body']);
    }

    // ---- POST /api/wallets/{id}/deposit ----

    public function testDeposit(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN);

        $response = $this->sendJson('POST', sprintf('/api/wallets/%d/deposit', $pln->getId()), $token, ['amount' => '500.00']);

        self::assertSame(200, $response['status']);
        self::assertSame('500.00', $response['body']['balance']);
    }

    public function testDepositAcceptsAmountAsJsonNumber(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN);

        $response = $this->sendJson('POST', sprintf('/api/wallets/%d/deposit', $pln->getId()), $token, ['amount' => 500]);

        self::assertSame(200, $response['status']);
        self::assertSame('500.00', $response['body']['balance']);
    }

    #[DataProvider('missingAmountProvider')]
    public function testDepositWithoutAmount(array $payload): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN);

        $response = $this->sendJson('POST', sprintf('/api/wallets/%d/deposit', $pln->getId()), $token, $payload);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Missing required field: amount.'], $response['body']);
    }

    public static function missingAmountProvider(): Generator
    {
        yield 'absent' => [[]];
        yield 'null' => [['amount' => null]];
    }

    #[DataProvider('invalidAmountProvider')]
    public function testDepositWithInvalidAmount(mixed $amount): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN);

        $response = $this->sendJson('POST', sprintf('/api/wallets/%d/deposit', $pln->getId()), $token, ['amount' => $amount]);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Amount must be a positive number.'], $response['body']);
    }

    public function testDepositWithTooPreciseAmount(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $jpy = $this->createWallet($user, Currency::JPY);

        $response = $this->sendJson('POST', sprintf('/api/wallets/%d/deposit', $jpy->getId()), $token, ['amount' => '100.5']);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Amount has too many decimal places for JPY.'], $response['body']);
    }

    public function testDepositAboveCurrencyLimit(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', sprintf('/api/wallets/%d/deposit', $eur->getId()), $token, ['amount' => '2500.01']);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Amount cannot exceed 2500.00 EUR.'], $response['body']);
    }

    public function testDepositToUnknownWallet(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets/999999999/deposit', $token, ['amount' => '10.00']);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => 'Wallet 999999999 not found.'], $response['body']);
    }

    public function testDepositToBlockedWallet(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, blocked: true);

        $response = $this->sendJson('POST', sprintf('/api/wallets/%d/deposit', $pln->getId()), $token, ['amount' => '10.00']);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => sprintf('Wallet %d is blocked.', $pln->getId())], $response['body']);
    }
}
