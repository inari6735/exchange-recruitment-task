<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Entity\UserToken;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Repository\UserRepository;
use App\Repository\UserTokenRepository;
use App\Repository\WalletRepository;
use App\ValueObject\Money;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Real HTTP requests against the app_test database. Every test creates its own user, so tests do not interfere;
 * data is not rolled back (requests run in their own database connection lifecycle).
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
    }

    /**
     * @return array{0: User, 1: string} the user and their API token
     */
    protected function createUserWithToken(): array
    {
        $user = new User(
            id: null,
            email: bin2hex(random_bytes(8)).'@example.com',
            roles: ['ROLE_USER'],
            createdAt: new DateTimeImmutable(),
        );
        static::getContainer()->get(UserRepository::class)->save($user);

        $token = UserToken::create(userId: $user->getIdNotNull(), expiresAt: new DateTimeImmutable('+1 day'));
        static::getContainer()->get(UserTokenRepository::class)->save($token);

        return [$user, $token->getToken()];
    }

    protected function createWallet(User $user, Currency $currency, string $balance = '0', bool $blocked = false): Wallet
    {
        $wallet = Wallet::create($user->getIdNotNull(), $currency);
        $amount = Money::of($balance, $currency);
        if ($amount->isGreaterThan(Money::zero($currency))) {
            $wallet->credit($amount);
        }
        $wallet->setIsBlocked($blocked);
        static::getContainer()->get(WalletRepository::class)->save($wallet);

        return $wallet;
    }

    /**
     * @return array{status: int, body: mixed, contentType: ?string}
     */
    protected function sendJson(
        string $method,
        string $uri,
        ?string $token,
        mixed $payload = null,
        ?string $rawBody = null,
        ?string $contentType = 'application/json',
    ): array {
        $server = [];
        if (null !== $token) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        if (null !== $contentType) {
            $server['CONTENT_TYPE'] = $contentType;
        }

        $content = $rawBody ?? (null === $payload ? null : json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        $this->client->request($method, $uri, server: $server, content: $content);
        $response = $this->client->getResponse();

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode((string) $response->getContent(), true),
            'contentType' => $response->headers->get('Content-Type'),
        ];
    }
}
