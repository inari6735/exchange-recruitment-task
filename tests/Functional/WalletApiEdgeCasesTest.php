<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Currency;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Inputs that used to crash (500) or mislead — after the refactor every one gets a JSON error.
 */
class WalletApiEdgeCasesTest extends ApiTestCase
{
    #[DataProvider('contentTypeProvider')]
    public function testContentTypeMustBeJson(?string $contentType, int $expectedStatus): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets', $token, ['currency' => 'GBP'], contentType: $contentType);

        self::assertSame($expectedStatus, $response['status']);
        if (415 === $expectedStatus) {
            self::assertSame(['error' => 'Unsupported content type, expected application/json.'], $response['body']);
        }
    }

    public static function contentTypeProvider(): Generator
    {
        yield 'json with charset' => ['application/json; charset=utf-8', 201];
        yield 'missing' => [null, 415];
        yield 'text' => ['text/plain', 415];
        yield 'form' => ['application/x-www-form-urlencoded', 415];
    }

    #[DataProvider('invalidJsonProvider')]
    public function testInvalidJsonBody(string $rawBody): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets', $token, rawBody: $rawBody);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Invalid JSON body.'], $response['body']);
        self::assertStringStartsWith('application/json', (string) $response['contentType']);
    }

    public static function invalidJsonProvider(): Generator
    {
        yield 'syntax error' => ['{bad'];
        yield 'empty body' => [''];
    }

    #[DataProvider('nonObjectBodyProvider')]
    public function testNonObjectJsonBodyIsNeverAServerError(string $rawBody, string $expectedError): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets', $token, rawBody: $rawBody);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => $expectedError], $response['body']);
    }

    public static function nonObjectBodyProvider(): Generator
    {
        // The serializer turns a scalar body into an empty request object — same answer as before the refactor.
        yield 'number' => ['5', 'Missing required field: currency.'];
        yield 'string' => ['"PLN"', 'Missing required field: currency.'];
        yield 'empty array' => ['[]', 'Missing required field: currency.'];
        yield 'list' => ['[1,2]', 'Missing required field: currency.'];
    }

    #[DataProvider('nonStringCurrencyProvider')]
    public function testNonStringCurrency(mixed $currency): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets', $token, ['currency' => $currency]);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => 'Invalid currency.'], $response['body']);
    }

    public static function nonStringCurrencyProvider(): Generator
    {
        yield 'array' => [['PLN']];
        yield 'number' => [123];
        yield 'boolean' => [false];
    }

    #[DataProvider('invalidWalletIdProvider')]
    public function testInvalidWalletIdInTransferBody(string $field, mixed $value): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00');
        $eur = $this->createWallet($user, Currency::EUR);
        $payload = ['fromWalletId' => $pln->getId(), 'toWalletId' => $eur->getId(), 'amount' => '10.00'];
        $payload[$field] = $value;

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, $payload);

        self::assertSame(400, $response['status']);
        self::assertSame(['error' => sprintf('%s must be a positive integer.', $field)], $response['body']);
    }

    public static function invalidWalletIdProvider(): Generator
    {
        yield 'from letters' => ['fromWalletId', 'abc'];
        yield 'from zero' => ['fromWalletId', 0];
        yield 'from negative' => ['fromWalletId', -1];
        yield 'from float' => ['fromWalletId', 1.5];
        yield 'from boolean' => ['fromWalletId', true];
        yield 'to letters' => ['toWalletId', 'x'];
    }

    public function testWalletIdAsDigitStringIsAccepted(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '500.00');
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => (string) $pln->getId(),
            'toWalletId' => (string) $eur->getId(),
            'amount' => '10.00',
        ]);

        self::assertSame(201, $response['status']);
    }

    public function testHugeNumericWalletIdIsNotAServerError(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $eur = $this->createWallet($user, Currency::EUR);

        $response = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => '99999999999999999999',
            'toWalletId' => $eur->getId(),
            'amount' => '10.00',
        ]);

        self::assertSame(404, $response['status']);
    }

    public function testNonNumericWalletIdInDepositPath(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets/abc/deposit', $token, ['amount' => '10.00']);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => 'Not found.'], $response['body']);
    }

    public function testHugeWalletIdInDepositPathIsNotAServerError(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('POST', '/api/wallets/99999999999999999999/deposit', $token, ['amount' => '1.00']);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => 'Not found.'], $response['body']);
    }

    public function testWrongMethodIsJson(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('PUT', '/api/wallets', $token, ['currency' => 'PLN']);

        self::assertSame(405, $response['status']);
        self::assertSame(['error' => 'Method Not Allowed'], $response['body']);
    }
}
