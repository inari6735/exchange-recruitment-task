<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Enum\Currency;

class WalletClosingApiTest extends ApiTestCase
{
    public function testCloseEmptyWallet(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $wallet = $this->createWallet($user, Currency::PLN);

        $response = $this->sendJson('DELETE', sprintf('/api/wallets/%d', $wallet->getId()), $token, contentType: null);

        self::assertSame(204, $response['status']);
        self::assertSame('', (string) $this->client->getResponse()->getContent());

        $list = $this->sendJson('GET', '/api/wallets', $token, contentType: null);
        self::assertSame([], $list['body']);
    }

    public function testClosingTwiceReturnsNotFound(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $wallet = $this->createWallet($user, Currency::PLN);
        $uri = sprintf('/api/wallets/%d', $wallet->getId());

        $this->sendJson('DELETE', $uri, $token, contentType: null);
        $response = $this->sendJson('DELETE', $uri, $token, contentType: null);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => sprintf('Wallet %d not found.', $wallet->getId())], $response['body']);
    }

    public function testCloseUnknownWallet(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('DELETE', '/api/wallets/999999999', $token, contentType: null);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => 'Wallet 999999999 not found.'], $response['body']);
    }

    public function testCloseAnotherUsersWallet(): void
    {
        [, $token] = $this->createUserWithToken();
        [$otherUser] = $this->createUserWithToken();
        $foreign = $this->createWallet($otherUser, Currency::PLN);

        $response = $this->sendJson('DELETE', sprintf('/api/wallets/%d', $foreign->getId()), $token, contentType: null);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => sprintf('Wallet %d not found.', $foreign->getId())], $response['body']);
    }

    public function testCloseBlockedWallet(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $wallet = $this->createWallet($user, Currency::PLN, blocked: true);

        $response = $this->sendJson('DELETE', sprintf('/api/wallets/%d', $wallet->getId()), $token, contentType: null);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => sprintf('Wallet %d is blocked.', $wallet->getId())], $response['body']);
    }

    public function testCloseWalletWithBalance(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $wallet = $this->createWallet($user, Currency::PLN, '0.01');

        $response = $this->sendJson('DELETE', sprintf('/api/wallets/%d', $wallet->getId()), $token, contentType: null);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => sprintf('Wallet %d has a non-zero balance.', $wallet->getId())], $response['body']);
    }

    public function testCloseWalletWithIncomingPendingTransfer(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '100.00');
        $eur = $this->createWallet($user, Currency::EUR);
        $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $eur->getId(),
            'amount' => '10.00',
        ]);

        $response = $this->sendJson('DELETE', sprintf('/api/wallets/%d', $eur->getId()), $token, contentType: null);

        self::assertSame(422, $response['status']);
        self::assertSame(['error' => sprintf('Wallet %d has pending transfers.', $eur->getId())], $response['body']);
    }

    public function testClosedWalletRejectsDepositAndTransfer(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $pln = $this->createWallet($user, Currency::PLN, '100.00');
        $eur = $this->createWallet($user, Currency::EUR);
        $this->sendJson('DELETE', sprintf('/api/wallets/%d', $eur->getId()), $token, contentType: null);
        $notFound = ['error' => sprintf('Wallet %d not found.', $eur->getId())];

        $deposit = $this->sendJson('POST', sprintf('/api/wallets/%d/deposit', $eur->getId()), $token, ['amount' => '10.00']);
        $transfer = $this->sendJson('POST', '/api/wallets/transfer', $token, [
            'fromWalletId' => $pln->getId(),
            'toWalletId' => $eur->getId(),
            'amount' => '10.00',
        ]);

        self::assertSame([404, $notFound], [$deposit['status'], $deposit['body']]);
        self::assertSame([404, $notFound], [$transfer['status'], $transfer['body']]);
    }

    public function testNewWalletInSameCurrencyAfterClosing(): void
    {
        [$user, $token] = $this->createUserWithToken();
        $old = $this->createWallet($user, Currency::PLN);
        $this->sendJson('DELETE', sprintf('/api/wallets/%d', $old->getId()), $token, contentType: null);

        $response = $this->sendJson('POST', '/api/wallets', $token, ['currency' => 'PLN']);

        self::assertSame(201, $response['status']);
        self::assertNotSame($old->getId(), $response['body']['id']);
    }

    public function testNonNumericIdIsNotFound(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('DELETE', '/api/wallets/abc', $token, contentType: null);

        self::assertSame(404, $response['status']);
        self::assertSame(['error' => 'Not found.'], $response['body']);
    }
}
