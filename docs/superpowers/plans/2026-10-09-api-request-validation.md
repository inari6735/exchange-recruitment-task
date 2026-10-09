# API Request Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kontroler `WalletController` przyjmuje zwalidowane DTO żądań (`#[MapRequestPayload]` + Symfony Validator), a błędy domenowe i HTTP mapuje jeden `ApiExceptionListener` — przy zachowaniu co do znaku każdej dzisiejszej „normalnej” odpowiedzi API.

**Architecture:** Testy charakteryzujące przez HTTP (`WebTestCase`) zamrażają obecny kontrakt przed refaktorem. Nowe klasy: `AmountInput` (normalizacja kwoty), constraint `PositiveAmount`, DTO `CreateWalletRequest`/`TransferRequest`/`DepositRequest` z `#[Assert\GroupSequence(['Presence', …])]`, `ApiExceptionListener` (`kernel.exception`, tylko `/api`). Kontroler zostaje cienki: DTO → serwis → odpowiedź.

**Tech Stack:** PHP 8.5, Symfony 8 (framework-bundle, http-kernel `MapRequestPayload`), nowe: `symfony/validator`, `symfony/serializer`, dev `symfony/browser-kit`; PHPUnit 13; MariaDB `app_test`.

**Spec:** `docs/superpowers/specs/2026-10-09-api-request-validation-design.md`

## Global Constraints

- Format każdego błędu API: `{"error": "<komunikat>"}`, `Content-Type: application/json`.
- Komunikaty zachowane dokładnie: `Missing required field: currency.`, `Missing required field: fromWalletId.`, `Missing required field: toWalletId.`, `Missing required field: amount.`, `Invalid currency.`, `Amount must be a positive number.` — wszystkie 400. Wyjątki domenowe: komunikat `getMessage()` bez zmian, statusy: `WalletNotFoundException` 404, `WalletAlreadyExistsException` 409, `InvalidMoneyAmountException` 400, `DepositLimitExceededException` 400, `SameWalletTransferException` 400, `WalletBlockedException` 422, `InsufficientFundsException` 422.
- Kolejność: najpierw obecność wszystkich pól (kolejność `fromWalletId`, `toWalletId`, `amount`), potem formaty; zwracany pierwszy błąd.
- Nowe komunikaty (przypadki brzegowe): `Invalid JSON body.` (400), `Unsupported content type, expected application/json.` (415), `fromWalletId must be a positive integer.` / `toWalletId must be a positive integer.` (400), `Not found.` (404), inne statusy HTTP → `Response::$statusTexts[$status]`.
- Kwota: string dziesiętny lub liczba JSON (`int`/`float` → `(string)`), musi spełniać `Money::isValidFormat()` i być > 0.
- ID portfeli w body: liczba całkowita JSON lub ciąg cyfr, dodatnie (`/^[1-9]\d*$/D`).
- Wymagany nagłówek `Content-Type: application/json` dla `POST`.
- Listener działa tylko dla ścieżek zaczynających się od `/api`; nieznane wyjątki (nie-domenowe, nie-HTTP) zostawia domyślnej obsłudze Symfony.
- Styl: `declare(strict_types=1)`, `@Symfony` php-cs-fixer z importami klas.
- Commity: tylko pliki z taska; **nigdy** `.env`. Każdy commit kończy się `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Serwer użytkownika: `https://127.0.0.1:8000` (`curl -k`) — nie uruchamiaj ani nie zabijaj serwerów. `cp` to alias `cp -i` — używaj `command cp -f`.

## Stan wyjściowy

Na `main` (`8e52897`): `php bin/phpunit` → 259 testów, 0 failures. Każdy task kończy się zielonym pełnym suite'em.

## Review Focus

1. **Kilka błędnych pól naraz** — brak pola zawsze wygrywa z błędnym formatem innego pola (np. `{"fromWalletId": "abc"}` bez `toWalletId` → `Missing required field: toWalletId.`). Testy: Task 1 `missingFieldProvider`, Task 3 `TransferRequestTest::testMissingFieldBeatsInvalidFormatOfAnotherField`.
2. **Body JSON, które nie jest obiektem** (`5`, `"x"`, `[]`, `[1,2]`) — nigdy 500: skalary → 400 `Invalid JSON body.`, tablice → walidacja pól (`Missing required field: …`). Test: Task 5 `nonObjectBodyProvider`.
3. **Bardzo duże ID jako ciąg cyfr** (`"99999999999999999999"`) — przechodzi walidację, `(int)` saturuje do `PHP_INT_MAX`, serwis zwraca 404, nie 500. Test: Task 5 `testHugeNumericWalletIdIsNotAServerError`.
4. **Warianty `Content-Type`** — `application/json; charset=utf-8` akceptowany; `text/plain`, `application/x-www-form-urlencoded`, brak → 415. Test: Task 5 `contentTypeProvider`.
5. **Granice listenera** — wyjątek spoza `/api` i nieznany wyjątek w `/api` nie są przechwytywane (dalej standardowe 500); nagłówki `HttpException` (np. `Allow` przy 405) przenoszone. Testy: Task 4.

---

## File Structure

**Create:**
- `src/Dto/Request/AmountInput.php` — normalizacja surowej kwoty do stringa.
- `src/Validator/PositiveAmount.php`, `src/Validator/PositiveAmountValidator.php` — constraint kwoty.
- `src/Dto/Request/CreateWalletRequest.php`, `TransferRequest.php`, `DepositRequest.php` — DTO żądań.
- `src/EventListener/ApiExceptionListener.php` — mapowanie wyjątków na JSON.
- `tests/Functional/ApiTestCase.php`, `tests/Functional/WalletApiContractTest.php`, `tests/Functional/WalletApiEdgeCasesTest.php`.
- `tests/Validator/PositiveAmountValidatorTest.php`, `tests/Dto/Request/{CreateWalletRequest,TransferRequest,DepositRequest}Test.php`, `tests/Dto/Request/AmountInputTest.php`, `tests/EventListener/ApiExceptionListenerTest.php`.

**Modify:** `composer.json`, `composer.lock`, `symfony.lock`, (pliki konfiguracyjne dodane przez recipe Flex), `src/Controller/WalletController.php`, `README.md`.

**Delete:** `tests/Controller/WalletControllerTest.php`.

---

### Task 1: Zależności i testy charakteryzujące (zamrożenie kontraktu)

**Files:**
- Modify: `composer.json`, `composer.lock`, `symfony.lock` (+ ewentualne `config/packages/*.yaml` z recipe)
- Create: `tests/Functional/ApiTestCase.php`, `tests/Functional/WalletApiContractTest.php`

**Interfaces:**
- Produces: `App\Tests\Functional\ApiTestCase` z `createUserWithToken(): array{0: User, 1: string}`, `createWallet(User $user, Currency $currency, string $balance = '0', bool $blocked = false): Wallet`, `sendJson(string $method, string $uri, ?string $token, mixed $payload = null, ?string $rawBody = null, ?string $contentType = 'application/json'): array{status: int, body: mixed, contentType: ?string}`.

- [ ] **Step 1: Install dependencies**

Run: `composer require symfony/validator symfony/serializer && composer require --dev symfony/browser-kit`
Expected: instalacja bez błędów; Flex może dodać pliki w `config/packages/` (np. `validator.yaml`) — zostają i trafiają do commita. Potem: `php bin/console lint:container && php bin/phpunit` → `[OK]` i `OK (259 tests, …)`.

- [ ] **Step 2: Base class for HTTP tests**

`tests/Functional/ApiTestCase.php`:

```php
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
```

- [ ] **Step 3: Write the characterization tests**

`tests/Functional/WalletApiContractTest.php`:

```php
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
        self::assertSame(
            ['error' => sprintf('Wallet for user %d in currency PLN already exists.', $user->getIdNotNull())],
            $response['body'],
        );
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
```

- [ ] **Step 4: Run them against the current controller**

Run: `php bin/phpunit tests/Functional/WalletApiContractTest.php`
Expected: `OK` — wszystkie przechodzą **na obecnym kodzie** (to jest dokładnie dzisiejsze zachowanie). Jeśli któryś test nie przechodzi, test opisuje zachowanie niezgodne z rzeczywistością — popraw **test** tak, by opisywał to, co API dziś faktycznie zwraca, i zanotuj to w raporcie (nie zmieniaj kodu produkcyjnego w tym tasku).

- [ ] **Step 5: Full suite and commit**

Run: `php bin/phpunit`
Expected: `OK` (259 + nowe testy).

```bash
git add composer.json composer.lock symfony.lock config/packages tests/Functional
git commit -m "test: freeze wallet API contract with HTTP characterization tests

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

(`git add config/packages` dodaje tylko pliki z recipe — sprawdź `git status`, że nic innego się nie zmieniło.)

---

### Task 2: `AmountInput` i constraint `PositiveAmount`

**Files:**
- Create: `src/Dto/Request/AmountInput.php`, `src/Validator/PositiveAmount.php`, `src/Validator/PositiveAmountValidator.php`, `tests/Dto/Request/AmountInputTest.php`, `tests/Validator/PositiveAmountValidatorTest.php`

**Interfaces:**
- Produces: `AmountInput::toDecimalString(mixed $value): ?string`; attribute `#[PositiveAmount]` (opcjonalne `message`, `groups`).

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Request/AmountInputTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\AmountInput;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AmountInputTest extends TestCase
{
    #[DataProvider('valueProvider')]
    public function testToDecimalString(mixed $value, ?string $expected): void
    {
        self::assertSame($expected, AmountInput::toDecimalString($value));
    }

    public static function valueProvider(): Generator
    {
        yield 'string kept' => ['100.50', '100.50'];
        yield 'int' => [100, '100'];
        yield 'float' => [100.5, '100.5'];
        yield 'float with zero fraction' => [100.0, '100'];
        yield 'bool' => [true, null];
        yield 'array' => [['100'], null];
        yield 'null' => [null, null];
    }
}
```

`tests/Validator/PositiveAmountValidatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Validator;

use App\Validator\PositiveAmount;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PositiveAmountValidatorTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidator();
    }

    #[DataProvider('validProvider')]
    public function testAcceptsPositiveAmounts(mixed $value): void
    {
        self::assertCount(0, $this->validator->validate($value, new PositiveAmount()));
    }

    public static function validProvider(): Generator
    {
        yield 'decimal string' => ['100.50'];
        yield 'integer string' => ['100'];
        yield 'small' => ['0.01'];
        yield 'json int' => [500];
        yield 'json float' => [100.5];
        yield 'null is left to NotNull' => [null];
    }

    #[DataProvider('invalidProvider')]
    public function testRejectsEverythingElse(mixed $value): void
    {
        $violations = $this->validator->validate($value, new PositiveAmount());

        self::assertCount(1, $violations);
        self::assertSame('Amount must be a positive number.', $violations[0]->getMessage());
    }

    public static function invalidProvider(): Generator
    {
        yield 'negative' => ['-50'];
        yield 'zero string' => ['0.00'];
        yield 'zero int' => [0];
        yield 'negative zero float' => [-0.0];
        yield 'letters' => ['abc'];
        yield 'exponent string' => ['1e3'];
        yield 'exponent float' => [1e25];
        yield 'leading space' => [' 100'];
        yield 'trailing newline' => ["100\n"];
        yield 'boolean' => [true];
        yield 'array' => [['100']];
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Request/AmountInputTest.php tests/Validator/PositiveAmountValidatorTest.php`
Expected: Errors — `Class "App\Dto\Request\AmountInput" not found`, `Class "App\Validator\PositiveAmount" not found`.

- [ ] **Step 3: Implement**

`src/Dto/Request/AmountInput.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Request;

/**
 * Amounts arrive either as decimal strings ("100.50") or JSON numbers (100.5).
 */
final class AmountInput
{
    public static function toDecimalString(mixed $value): ?string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return \is_string($value) ? $value : null;
    }
}
```

`src/Validator/PositiveAmount.php`:

```php
<?php

declare(strict_types=1);

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * A positive decimal amount given as a string or a JSON number. Currency precision is checked later by Money::of().
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_METHOD | Attribute::TARGET_PARAMETER)]
final class PositiveAmount extends Constraint
{
    public function __construct(
        public string $message = 'Amount must be a positive number.',
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(groups: $groups, payload: $payload);
    }
}
```

`src/Validator/PositiveAmountValidator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Validator;

use App\Dto\Request\AmountInput;
use App\ValueObject\Money;
use BcMath\Number;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class PositiveAmountValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof PositiveAmount) {
            throw new UnexpectedTypeException($constraint, PositiveAmount::class);
        }

        if (null === $value) {
            return;
        }

        $amount = AmountInput::toDecimalString($value);

        if (null === $amount || !Money::isValidFormat($amount) || new Number($amount)->compare(0) <= 0) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
```

Jeśli konstruktor `Symfony\Component\Validator\Constraint` w zainstalowanej wersji nie przyjmuje nazwanych argumentów `groups:`/`payload:` — sprawdź jego sygnaturę w `vendor/symfony/validator/Constraint.php` i dopasuj wywołanie `parent::__construct(...)`, odnotuj w raporcie.

- [ ] **Step 4: Run to verify they pass, full suite**

Run: `php bin/phpunit tests/Dto/Request/AmountInputTest.php tests/Validator/PositiveAmountValidatorTest.php && php bin/phpunit`
Expected: `OK`; pełny suite `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Dto/Request/AmountInput.php src/Validator tests/Dto/Request/AmountInputTest.php tests/Validator
git commit -m "feat: add PositiveAmount constraint for API amounts

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: DTO żądań z walidacją

**Files:**
- Create: `src/Dto/Request/CreateWalletRequest.php`, `src/Dto/Request/TransferRequest.php`, `src/Dto/Request/DepositRequest.php`, `tests/Dto/Request/CreateWalletRequestTest.php`, `tests/Dto/Request/TransferRequestTest.php`, `tests/Dto/Request/DepositRequestTest.php`

**Interfaces:**
- Consumes: `AmountInput::toDecimalString()`, `#[PositiveAmount]`, `Currency`.
- Produces:
  - `new CreateWalletRequest(mixed $currency = null)`, `currency(): Currency`, `static currencyCodes(): list<string>`.
  - `new TransferRequest(mixed $fromWalletId = null, mixed $toWalletId = null, mixed $amount = null)`, `fromWalletId(): int`, `toWalletId(): int`, `amount(): string`.
  - `new DepositRequest(mixed $amount = null)`, `amount(): string`.
  - Grupa walidacji obecności: `'Presence'`; sekwencja `#[Assert\GroupSequence(['Presence', '<ShortClassName>'])]`.

- [ ] **Step 1: Write the failing tests**

`tests/Dto/Request/CreateWalletRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\CreateWalletRequest;
use App\Enum\Currency;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class CreateWalletRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testValidCurrency(): void
    {
        $request = new CreateWalletRequest('EUR');

        self::assertCount(0, $this->validator->validate($request));
        self::assertSame(Currency::EUR, $request->currency());
    }

    public function testMissingCurrency(): void
    {
        $violations = $this->validator->validate(new CreateWalletRequest());

        self::assertCount(1, $violations);
        self::assertSame('Missing required field: currency.', $violations[0]->getMessage());
    }

    #[DataProvider('invalidProvider')]
    public function testInvalidCurrency(mixed $currency): void
    {
        $violations = $this->validator->validate(new CreateWalletRequest($currency));

        self::assertCount(1, $violations);
        self::assertSame('Invalid currency.', $violations[0]->getMessage());
    }

    public static function invalidProvider(): Generator
    {
        yield 'unknown' => ['XYZ'];
        yield 'lowercase' => ['pln'];
        yield 'empty' => [''];
        yield 'number' => [123];
        yield 'array' => [['PLN']];
        yield 'boolean' => [true];
    }

    public function testCurrencyCodesListAllCurrencies(): void
    {
        self::assertSame(['PLN', 'EUR', 'USD', 'GBP', 'JPY', 'CHF', 'HUF'], CreateWalletRequest::currencyCodes());
    }
}
```

`tests/Dto/Request/TransferRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\TransferRequest;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TransferRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testValidRequestAndTypedGetters(): void
    {
        $request = new TransferRequest('12', 34, 100.5);

        self::assertCount(0, $this->validator->validate($request));
        self::assertSame(12, $request->fromWalletId());
        self::assertSame(34, $request->toWalletId());
        self::assertSame('100.5', $request->amount());
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[DataProvider('firstErrorProvider')]
    public function testFirstViolationMatchesLegacyOrder(array $fields, string $expectedMessage): void
    {
        $violations = $this->validator->validate(new TransferRequest(...$fields));

        self::assertGreaterThan(0, \count($violations));
        self::assertSame($expectedMessage, $violations[0]->getMessage());
    }

    public static function firstErrorProvider(): Generator
    {
        yield 'everything missing' => [[], 'Missing required field: fromWalletId.'];
        yield 'toWalletId missing' => [['fromWalletId' => 1, 'amount' => '10'], 'Missing required field: toWalletId.'];
        yield 'amount missing' => [['fromWalletId' => 1, 'toWalletId' => 2], 'Missing required field: amount.'];
        yield 'invalid amount' => [['fromWalletId' => 1, 'toWalletId' => 2, 'amount' => '-1'], 'Amount must be a positive number.'];
        yield 'invalid fromWalletId' => [['fromWalletId' => 'abc', 'toWalletId' => 2, 'amount' => '10'], 'fromWalletId must be a positive integer.'];
        yield 'invalid toWalletId' => [['fromWalletId' => 1, 'toWalletId' => 'x', 'amount' => '10'], 'toWalletId must be a positive integer.'];
    }

    public function testMissingFieldBeatsInvalidFormatOfAnotherField(): void
    {
        $violations = $this->validator->validate(new TransferRequest(fromWalletId: 'abc', amount: '-1'));

        self::assertSame('Missing required field: toWalletId.', $violations[0]->getMessage());
    }

    #[DataProvider('invalidIdProvider')]
    public function testRejectsInvalidWalletId(mixed $id): void
    {
        $violations = $this->validator->validate(new TransferRequest($id, 2, '10'));

        self::assertCount(1, $violations);
        self::assertSame('fromWalletId must be a positive integer.', $violations[0]->getMessage());
    }

    public static function invalidIdProvider(): Generator
    {
        yield 'letters' => ['abc'];
        yield 'zero' => [0];
        yield 'negative' => [-1];
        yield 'float' => [1.5];
        yield 'float with zero fraction' => [2.0];
        yield 'boolean' => [true];
        yield 'leading zero' => ['012'];
        yield 'array' => [[1]];
        yield 'trailing newline' => ["12\n"];
    }
}
```

`tests/Dto/Request/DepositRequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Dto\Request;

use App\Dto\Request\DepositRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class DepositRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();
    }

    public function testValidAmount(): void
    {
        $request = new DepositRequest(500);

        self::assertCount(0, $this->validator->validate($request));
        self::assertSame('500', $request->amount());
    }

    public function testMissingAmount(): void
    {
        $violations = $this->validator->validate(new DepositRequest());

        self::assertCount(1, $violations);
        self::assertSame('Missing required field: amount.', $violations[0]->getMessage());
    }

    public function testInvalidAmount(): void
    {
        $violations = $this->validator->validate(new DepositRequest('-50'));

        self::assertCount(1, $violations);
        self::assertSame('Amount must be a positive number.', $violations[0]->getMessage());
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Dto/Request`
Expected: Errors — `Class "App\Dto\Request\CreateWalletRequest" not found` itd. (`AmountInputTest` przechodzi).

- [ ] **Step 3: Implement**

`src/Dto/Request/CreateWalletRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Enum\Currency;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Raw JSON values are kept as mixed, so wrong types produce our messages instead of deserialization errors.
 * Presence of every field is checked before any format (the API reports the first problem).
 */
#[Assert\GroupSequence(['Presence', 'CreateWalletRequest'])]
final readonly class CreateWalletRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Missing required field: currency.', groups: ['Presence'])]
        #[Assert\Choice(callback: [self::class, 'currencyCodes'], message: 'Invalid currency.')]
        public mixed $currency = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function currencyCodes(): array
    {
        return array_map(static fn (Currency $currency): string => $currency->value, Currency::cases());
    }

    public function currency(): Currency
    {
        return Currency::from($this->currency);
    }
}
```

`src/Dto/Request/TransferRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Validator\PositiveAmount;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Wallet ids: a positive JSON integer or a string of digits. Amount: a positive decimal string or JSON number.
 * Presence of every field is checked before any format, in field order (the API reports the first problem).
 */
#[Assert\GroupSequence(['Presence', 'TransferRequest'])]
final readonly class TransferRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Missing required field: fromWalletId.', groups: ['Presence'])]
        #[Assert\Sequentially([
            new Assert\Type(type: ['integer', 'string'], message: 'fromWalletId must be a positive integer.'),
            new Assert\Regex(pattern: '/^[1-9]\d*$/D', message: 'fromWalletId must be a positive integer.'),
        ])]
        public mixed $fromWalletId = null,
        #[Assert\NotNull(message: 'Missing required field: toWalletId.', groups: ['Presence'])]
        #[Assert\Sequentially([
            new Assert\Type(type: ['integer', 'string'], message: 'toWalletId must be a positive integer.'),
            new Assert\Regex(pattern: '/^[1-9]\d*$/D', message: 'toWalletId must be a positive integer.'),
        ])]
        public mixed $toWalletId = null,
        #[Assert\NotNull(message: 'Missing required field: amount.', groups: ['Presence'])]
        #[PositiveAmount]
        public mixed $amount = null,
    ) {
    }

    public function fromWalletId(): int
    {
        return (int) $this->fromWalletId;
    }

    public function toWalletId(): int
    {
        return (int) $this->toWalletId;
    }

    public function amount(): string
    {
        return (string) AmountInput::toDecimalString($this->amount);
    }
}
```

`src/Dto/Request/DepositRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\Request;

use App\Validator\PositiveAmount;
use Symfony\Component\Validator\Constraints as Assert;

#[Assert\GroupSequence(['Presence', 'DepositRequest'])]
final readonly class DepositRequest
{
    public function __construct(
        #[Assert\NotNull(message: 'Missing required field: amount.', groups: ['Presence'])]
        #[PositiveAmount]
        public mixed $amount = null,
    ) {
    }

    public function amount(): string
    {
        return (string) AmountInput::toDecimalString($this->amount);
    }
}
```

- [ ] **Step 4: Run to verify they pass, full suite**

Run: `php bin/phpunit tests/Dto/Request && php bin/phpunit`
Expected: `OK`; pełny suite `OK`.

Jeśli kolejność naruszeń w `testFirstViolationMatchesLegacyOrder` / `testMissingFieldBeatsInvalidFormatOfAnotherField` nie zgadza się (np. walidator nie respektuje `GroupSequence` dla atrybutów na promowanych parametrach konstruktora) — zbadaj przyczynę (systematic-debugging), nie zmieniaj oczekiwanych komunikatów; kolejność jest wymaganiem kontraktu.

- [ ] **Step 5: Commit**

```bash
git add src/Dto/Request tests/Dto/Request
git commit -m "feat: add validated request DTOs for wallet endpoints

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `ApiExceptionListener`

**Files:**
- Create: `src/EventListener/ApiExceptionListener.php`, `tests/EventListener/ApiExceptionListenerTest.php`

**Interfaces:**
- Consumes: wyjątki domenowe z `App\Exception\*`.
- Produces: listener `kernel.exception` (autokonfiguracja przez `#[AsEventListener]`), ustawia `JsonResponse {"error": …}` dla `/api*`.

- [ ] **Step 1: Write the failing tests**

`tests/EventListener/ApiExceptionListenerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Enum\Currency;
use App\EventListener\ApiExceptionListener;
use App\Exception\DepositLimitExceededException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\ValueObject\Money;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

class ApiExceptionListenerTest extends TestCase
{
    #[DataProvider('domainExceptionProvider')]
    public function testMapsDomainExceptions(Throwable $exception, int $status): void
    {
        $event = $this->dispatch($exception);

        self::assertSame($status, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => $exception->getMessage()], $this->body($event));
    }

    public static function domainExceptionProvider(): Generator
    {
        yield 'not found' => [new WalletNotFoundException(7), 404];
        yield 'already exists' => [new WalletAlreadyExistsException(1, Currency::PLN), 409];
        yield 'invalid money' => [InvalidMoneyAmountException::tooManyDecimalPlaces(Currency::PLN), 400];
        yield 'deposit limit' => [new DepositLimitExceededException(Money::of('2500', Currency::EUR)), 400];
        yield 'same wallet' => [new SameWalletTransferException(), 400];
        yield 'blocked' => [new WalletBlockedException(3), 422];
        yield 'insufficient funds' => [new InsufficientFundsException(3), 422];
    }

    public function testValidationFailureReturnsFirstViolation(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('Invalid currency.', null, [], null, 'currency', 'XYZ'),
            new ConstraintViolation('Second problem.', null, [], null, 'other', 'x'),
        ]);
        $exception = new HttpException(400, "Invalid currency.\nSecond problem.", new ValidationFailedException(new stdClass(), $violations));

        $event = $this->dispatch($exception);

        self::assertSame(400, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Invalid currency.'], $this->body($event));
    }

    public function testValidationFailureWithoutPayloadObjectIsInvalidJson(): void
    {
        $violations = new ConstraintViolationList([
            new ConstraintViolation('This value should be of type array.', null, [], null, '', 5),
        ]);
        $exception = new HttpException(400, 'x', new ValidationFailedException(null, $violations));

        self::assertSame(['error' => 'Invalid JSON body.'], $this->body($this->dispatch($exception)));
    }

    public function testMalformedJsonBody(): void
    {
        $exception = new BadRequestHttpException('Request payload contains invalid "json" data.', new NotEncodableValueException('Syntax error'));

        $event = $this->dispatch($exception);

        self::assertSame(400, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Invalid JSON body.'], $this->body($event));
    }

    public function testEmptyBody(): void
    {
        self::assertSame(['error' => 'Invalid JSON body.'], $this->body($this->dispatch(HttpException::fromStatusCode(400))));
    }

    public function testUnsupportedMediaType(): void
    {
        $event = $this->dispatch(new UnsupportedMediaTypeHttpException('Unsupported format.'));

        self::assertSame(415, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Unsupported content type, expected application/json.'], $this->body($event));
    }

    public function testNotFound(): void
    {
        $event = $this->dispatch(new NotFoundHttpException('No route found for "POST /api/wallets/abc/deposit"'));

        self::assertSame(404, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Not found.'], $this->body($event));
    }

    public function testOtherHttpExceptionUsesStatusTextAndKeepsHeaders(): void
    {
        $event = $this->dispatch(new MethodNotAllowedHttpException(['GET', 'POST']));

        self::assertSame(405, $event->getResponse()?->getStatusCode());
        self::assertSame(['error' => 'Method Not Allowed'], $this->body($event));
        self::assertSame('GET, POST', $event->getResponse()?->headers->get('Allow'));
    }

    public function testResponseIsJson(): void
    {
        $event = $this->dispatch(new WalletNotFoundException(7));

        self::assertSame('application/json', $event->getResponse()?->headers->get('Content-Type'));
    }

    public function testIgnoresPathsOutsideApi(): void
    {
        $event = $this->dispatch(new WalletNotFoundException(7), '/health');

        self::assertNull($event->getResponse());
    }

    public function testLeavesUnknownExceptionsToSymfony(): void
    {
        $event = $this->dispatch(new RuntimeException('boom'));

        self::assertNull($event->getResponse());
    }

    private function dispatch(Throwable $exception, string $path = '/api/wallets'): ExceptionEvent
    {
        $event = new ExceptionEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create($path, 'POST'),
            HttpKernelInterface::MAIN_REQUEST,
            $exception,
        );

        new ApiExceptionListener()($event);

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(ExceptionEvent $event): array
    {
        return json_decode((string) $event->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/EventListener`
Expected: Errors — `Class "App\EventListener\ApiExceptionListener" not found`.

- [ ] **Step 3: Implement**

`src/EventListener/ApiExceptionListener.php`:

```php
<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Exception\DepositLimitExceededException;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Throwable;

/**
 * Turns domain and HTTP exceptions raised under /api into {"error": "..."} JSON responses.
 * Unknown exceptions are left to Symfony (they stay 500).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ApiExceptionListener
{
    /** HTTP knowledge stays in the HTTP layer; domain exceptions don't know about status codes. */
    private const array DOMAIN_EXCEPTION_STATUS = [
        WalletNotFoundException::class => Response::HTTP_NOT_FOUND,
        WalletAlreadyExistsException::class => Response::HTTP_CONFLICT,
        InvalidMoneyAmountException::class => Response::HTTP_BAD_REQUEST,
        DepositLimitExceededException::class => Response::HTTP_BAD_REQUEST,
        SameWalletTransferException::class => Response::HTTP_BAD_REQUEST,
        WalletBlockedException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        InsufficientFundsException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
    ];

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api')) {
            return;
        }

        $response = $this->toResponse($event->getThrowable());
        if (null !== $response) {
            $event->setResponse($response);
        }
    }

    private function toResponse(Throwable $exception): ?JsonResponse
    {
        foreach (self::DOMAIN_EXCEPTION_STATUS as $class => $status) {
            if ($exception instanceof $class) {
                return self::error($exception->getMessage(), $status);
            }
        }

        if (!$exception instanceof HttpExceptionInterface) {
            return null;
        }

        $status = $exception->getStatusCode();
        $previous = $exception->getPrevious();

        $message = match (true) {
            $previous instanceof ValidationFailedException && \is_object($previous->getValue())
                => (string) $previous->getViolations()->get(0)->getMessage(),
            $exception instanceof UnsupportedMediaTypeHttpException => 'Unsupported content type, expected application/json.',
            Response::HTTP_BAD_REQUEST === $status => 'Invalid JSON body.',
            $exception instanceof NotFoundHttpException => 'Not found.',
            default => Response::$statusTexts[$status] ?? 'Error',
        };

        return self::error($message, $status, $exception->getHeaders());
    }

    /**
     * @param array<string, string> $headers
     */
    private static function error(string $message, int $status, array $headers = []): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status, $headers);
    }
}
```

- [ ] **Step 4: Run to verify they pass, full suite**

Run: `php bin/phpunit tests/EventListener && php bin/console lint:container && php bin/phpunit`
Expected: `OK`; container OK; pełny suite `OK` (`WalletApiContractTest` dalej zielony — kontroler jeszcze łapie wyjątki sam).

- [ ] **Step 5: Commit**

```bash
git add src/EventListener tests/EventListener
git commit -m "feat: map API exceptions to JSON error responses in one listener

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Cienki kontroler + przypadki brzegowe

**Files:**
- Modify: `src/Controller/WalletController.php`
- Create: `tests/Functional/WalletApiEdgeCasesTest.php`
- Delete: `tests/Controller/WalletControllerTest.php`

**Interfaces:**
- Consumes: DTO z Taska 3, listener z Taska 4, `ApiTestCase` z Taska 1.

- [ ] **Step 1: Write the failing edge-case tests**

`tests/Functional/WalletApiEdgeCasesTest.php`:

```php
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
        yield 'number' => ['5', 'Invalid JSON body.'];
        yield 'string' => ['"PLN"', 'Invalid JSON body.'];
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

    public function testWrongMethodIsJson(): void
    {
        [, $token] = $this->createUserWithToken();

        $response = $this->sendJson('PUT', '/api/wallets', $token, ['currency' => 'PLN']);

        self::assertSame(405, $response['status']);
        self::assertSame(['error' => 'Method Not Allowed'], $response['body']);
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Functional/WalletApiEdgeCasesTest.php`
Expected: FAIL — m.in. `missing`/`text`/`form` Content-Type → 201 zamiast 415; `{bad` → 500; `"currency": ["PLN"]` → 500; `fromWalletId: "abc"` → 404; `/abc/deposit` → 500. (Przypadki, które już dziś działają — np. `[]` → `Missing required field: currency.` — mogą przechodzić; to w porządku.)

- [ ] **Step 3: Rewrite the controller**

`src/Controller/WalletController.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Controller;

use App\Dto\Request\CreateWalletRequest;
use App\Dto\Request\DepositRequest;
use App\Dto\Request\TransferRequest;
use App\Dto\TransactionResponse;
use App\Dto\WalletResponse;
use App\Entity\User;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Service\TransferService;
use App\Service\WalletService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Request validation lives in the request DTOs; errors become JSON in App\EventListener\ApiExceptionListener.
 */
#[Route('/api/wallets')]
final class WalletController extends AbstractController
{
    public function __construct(
        private readonly WalletService $walletService,
        private readonly WalletRepositoryInterface $walletRepository,
        private readonly TransferService $transferService,
        private readonly DepositService $depositService,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $wallets = $this->walletRepository->findByUserId($user->getIdNotNull());

        return new JsonResponse(array_map(static fn ($w) => new WalletResponse($w), $wallets));
    }

    #[Route('', methods: ['POST'])]
    public function create(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        CreateWalletRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $wallet = $this->walletService->createWallet($user->getIdNotNull(), $payload->currency());

        return new JsonResponse(new WalletResponse($wallet), Response::HTTP_CREATED);
    }

    #[Route('/transfer', methods: ['POST'])]
    public function transfer(
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        TransferRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $transaction = $this->transferService->transfer(
            $user->getIdNotNull(),
            $payload->fromWalletId(),
            $payload->toWalletId(),
            $payload->amount(),
        );

        return new JsonResponse(new TransactionResponse($transaction), Response::HTTP_CREATED);
    }

    #[Route('/{id}/deposit', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deposit(
        int $id,
        #[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: Response::HTTP_BAD_REQUEST)]
        DepositRequest $payload,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $wallet = $this->depositService->deposit($user->getIdNotNull(), $id, $payload->amount());

        return new JsonResponse(new WalletResponse($wallet));
    }
}
```

Usuń `tests/Controller/WalletControllerTest.php` (`git rm`) — jego przypadki pokrywają `WalletApiContractTest` i `WalletApiEdgeCasesTest`.

- [ ] **Step 4: Run all HTTP tests and the full suite**

Run: `php bin/phpunit tests/Functional && php bin/console lint:container && php bin/phpunit`
Expected: `OK` — **`WalletApiContractTest` bez żadnej zmiany w pliku testu** (kryterium refaktoru: `git diff -- tests/Functional/WalletApiContractTest.php` pusty, a `git log --oneline -- tests/Functional/WalletApiContractTest.php` pokazuje tylko commit z Taska 1) i wszystkie przypadki brzegowe zielone; pełny suite `OK`.

Jeśli `"5"`/`"PLN"` jako body daje 500 zamiast 400 (serializer rzuca wyjątek nieprzechwytywany przez `MapRequestPayload`) — zbadaj klasę wyjątku i obsłuż ją w `ApiExceptionListener` jako `Invalid JSON body.` (400) z testem jednostkowym w `ApiExceptionListenerTest`; odnotuj w raporcie.

- [ ] **Step 5: Verify live**

```bash
TOKEN=$(php bin/console app:create-user | grep -oE '[a-f0-9]{64}' | tail -1); A="Authorization: Bearer $TOKEN"; J="Content-Type: application/json"; U=https://127.0.0.1:8000/api/wallets
curl -sk -X POST -H "$A" -H "$J" -d '{"currency":"XYZ"}' $U; echo
curl -sk -X POST -H "$A" -H "$J" -d '{"currency":"PLN"}' $U; echo
curl -sk -X POST -H "$A" -H "$J" -d '{bad' $U; echo
curl -sk -X POST -H "$A" -d '{"currency":"EUR"}' $U; echo
curl -sk -X POST -H "$A" -H "$J" -d '{"fromWalletId":"abc","toWalletId":1,"amount":"1"}' $U/transfer; echo
curl -sk -X POST -H "$A" -H "$J" -d '{"amount":"1"}' $U/abc/deposit; echo
```

Expected (kolejno): `{"error":"Invalid currency."}`; portfel PLN (201); `{"error":"Invalid JSON body."}`; `{"error":"Unsupported content type, expected application/json."}`; `{"error":"fromWalletId must be a positive integer."}`; `{"error":"Not found."}`.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/WalletController.php tests/Functional/WalletApiEdgeCasesTest.php
git rm tests/Controller/WalletControllerTest.php
git commit -m "refactor: thin WalletController with validated request DTOs

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: README, styl, pełny suite

**Files:**
- Modify: `README.md`

- [ ] **Step 1: README**

W sekcji `## API Endpoints`, bezpośrednio pod zdaniem `All endpoints require Bearer token authentication. Obtain a token with \`app:create-user\`.`, dodaj:

```markdown
Requests with a body must be sent as JSON with the `Content-Type: application/json` header (otherwise `415`).
Errors are returned as JSON: `{ "error": "<message>" }` — `400` for invalid input or malformed JSON, `404` for
unknown wallets, `409` for a duplicate wallet, `422` for blocked wallets or insufficient funds.
```

- [ ] **Step 2: Style and full suite**

Run:

```bash
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --path-mode=intersection --dry-run --diff $(git diff --name-only --diff-filter=AM 8e52897 -- src tests)
php bin/phpunit
grep -n "json_decode\|try {\|catch (\|parsePositiveAmount" src/Controller/WalletController.php
```

Expected: cs-fixer bez diffów (jeśli są — uruchom bez `--dry-run --diff` na tych plikach, ponów `php bin/phpunit`, dołącz do commita); pełny suite `OK`; `grep` nic nie znajduje.

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: document JSON content type and error format

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
