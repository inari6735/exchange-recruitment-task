# Wallet Closing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `DELETE /api/wallets/{id}` zamyka (soft delete) pusty, niezablokowany portfel bez oczekujących przelewów; zamknięty portfel znika z API, a w tej samej walucie można założyć nowy.

**Architecture:** Kolumna `wallets.closed_at` + wyliczana `open_flag` (1 dla otwartych, `NULL` dla zamkniętych) z unikalnym indeksem `(user_id, currency, open_flag)`. Reguły w encji (`Wallet::close()`), orkiestracja w `WalletService::closeWallet()` w transakcji DB z blokadą wiersza, sprawdzenie oczekujących przelewów przez `TransactionRepositoryInterface::hasInFlightTransfers()`. Pozostałe serwisy traktują zamknięty portfel jak nieistniejący (404); błędy mapuje istniejący `ApiExceptionListener`.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine DBAL 4 + Migrations, MariaDB 11.4, PHPUnit 13.

**Spec:** `docs/superpowers/specs/2026-10-09-wallet-closing-design.md`

## Global Constraints

- Endpoint: `DELETE /api/wallets/{id}`, `requirements: ['id' => '\d{1,18}']`, sukces `204` z pustym body.
- Kolejność i komunikaty: 404 `Wallet {id} not found.` (brak / cudzy / już zamknięty) → 422 `Wallet {id} is blocked.` → 422 `Wallet {id} has a non-zero balance.` → 422 `Wallet {id} has pending transfers.`
- Oczekujące przelewy = status `pending` lub `fraud_review`, portfel jako źródło **lub** cel.
- Zamknięty portfel: pomijany przez `GET /api/wallets`; wpłata/przelew (źródło lub cel) → 404 `Wallet {id} not found.`; nie blokuje `POST /api/wallets` w tej samej walucie; `complete()` traktuje go jak zablokowany.
- Zamknięcie nie zmienia salda, rezerwacji ani transakcji.
- `WalletApiContractTest` (kontrakt API) — bez żadnej zmiany w pliku.
- Styl: `declare(strict_types=1)`, `@Symfony` php-cs-fixer z importami klas i funkcji.
- Commity: tylko pliki z taska; **nigdy** `.env`. Każdy commit kończy się `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Serwer użytkownika: `https://127.0.0.1:8000` (`curl -k`) — nie uruchamiaj ani nie zabijaj serwerów. `cp` to alias `cp -i` — używaj `command cp -f`.

## Stan wyjściowy

Na `main` (`2c0ac22`): `php bin/phpunit` → 358 testów, 0 failures. Każdy task kończy się zielonym pełnym suite'em.

## Review Focus

1. **Wyścig zamknięcia z przelewem** — oba blokują wiersz portfela przed odczytem, więc wykonują się sekwencyjnie. Test: Task 3 `testCloseLocksWalletBeforeReadingIt`.
2. **Portfel z historycznym ujemnym saldem** (`-995.00`) — zamknięcie → 422 `non-zero balance`, nie inny błąd. Testy: Task 1 `testCloseRejectsNegativeLegacyBalance`, Task 3 `testCloseRejectsNegativeLegacyBalance`.
3. **Przychodzący oczekujący przelew** (saldo 0, portfel jest celem `pending`) — 422 `pending transfers`. Test: Task 5 `testCloseWalletWithIncomingPendingTransfer`.
4. **Wielokrotne zamykanie w tej samej walucie** (zamknij → załóż → zamknij) — dwa zamknięte portfele w tej samej walucie nie łamią unikalności. Test: Task 2 `testManyClosedWalletsInSameCurrencyAreAllowed`.
5. **Kolumna wyliczana** `open_flag` nie może być zapisywana przez `insert`/`update` (MariaDB odrzuca zapis) — każde `save()` po migracji musi działać. Testy: Task 2 (insert + update + reload).

---

## File Structure

**Create:** `src/Exception/WalletNotEmptyException.php`, `src/Exception/WalletHasPendingTransfersException.php`, `migrations/Version20261009140000.php`, `tests/Integration/Repository/WalletClosingPersistenceTest.php`, `tests/Functional/WalletClosingApiTest.php`.

**Modify:** `src/Entity/Wallet.php`, `src/Repository/WalletRepository{,Interface}.php`, `src/Repository/TransactionRepository{,Interface}.php`, `src/Service/WalletService.php`, `src/Service/TransferService.php`, `src/Service/DepositService.php`, `src/Service/TransactionProcessorService.php`, `src/EventListener/ApiExceptionListener.php`, `src/Controller/WalletController.php`, `tests/Support/WalletFixture.php`, `tests/Entity/WalletTest.php`, `tests/Service/{Wallet,Transfer,Deposit,TransactionProcessor}ServiceTest.php`, `tests/EventListener/ApiExceptionListenerTest.php`, `tests/Integration/Repository/TransactionRepositoryTest.php`, `tests/Integration/TransferAtomicityTest.php`, `docs/business-logic.md`, `README.md`, `exchange-api.postman_collection.json`.

---

### Task 1: `Wallet::close()` i wyjątki

**Files:**
- Create: `src/Exception/WalletNotEmptyException.php`, `src/Exception/WalletHasPendingTransfersException.php`
- Modify: `src/Entity/Wallet.php`, `tests/Support/WalletFixture.php`
- Test: `tests/Entity/WalletTest.php`

**Interfaces:**
- Produces:
  - `Wallet::__construct(..., DateTimeImmutable $createdAt, ?DateTimeImmutable $closedAt = null)` (nowy ostatni argument).
  - `Wallet::getClosedAt(): ?DateTimeImmutable`, `isClosed(): bool`, `close(DateTimeImmutable $at): void`.
  - `WalletNotEmptyException(int $walletId)` — `Wallet {id} has a non-zero balance.`; `WalletHasPendingTransfersException(int $walletId)` — `Wallet {id} has pending transfers.`
  - `WalletFixture::create(int $id, int $userId, Currency $currency, string $balance = '0', string $reserved = '0', bool $blocked = false, bool $closed = false): Wallet`.

- [ ] **Step 1: Write the failing tests**

`tests/Support/WalletFixture.php` — dodaj parametr `bool $closed = false` (ostatni) i argument konstruktora:

```php
    public static function create(
        int $id,
        int $userId,
        Currency $currency,
        string $balance = '0',
        string $reserved = '0',
        bool $blocked = false,
        bool $closed = false,
    ): Wallet {
        return new Wallet(
            id: $id,
            userId: $userId,
            currency: $currency,
            balance: Money::of($balance, $currency),
            reserved: Money::of($reserved, $currency),
            isBlocked: $blocked,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
            closedAt: $closed ? new DateTimeImmutable('-1 day') : null,
        );
    }
```

`tests/Entity/WalletTest.php` — dodaj importy `use App\Exception\WalletHasPendingTransfersException;`, `use App\Exception\WalletNotEmptyException;` i testy przed zamykającą klamrą klasy:

```php
    public function testCreatedWalletIsOpen(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);

        $this->assertFalse($wallet->isClosed());
        $this->assertNull($wallet->getClosedAt());
    }

    public function testCloseEmptyWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN);
        $at = new DateTimeImmutable('2026-10-09 12:00:00');

        $wallet->close($at);

        $this->assertTrue($wallet->isClosed());
        $this->assertSame($at, $wallet->getClosedAt());
        $this->assertSame('0.00', $wallet->getBalance()->toString());
    }

    public function testCloseRejectsNonZeroBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '0.01');

        $this->expectException(WalletNotEmptyException::class);
        $this->expectExceptionMessage('Wallet 7 has a non-zero balance.');

        $wallet->close(new DateTimeImmutable());
    }

    public function testCloseRejectsNegativeLegacyBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '-995.00');

        $this->expectException(WalletNotEmptyException::class);

        $wallet->close(new DateTimeImmutable());
    }

    public function testCloseRejectsReservation(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '0', '5.00');

        $this->expectException(WalletHasPendingTransfersException::class);
        $this->expectExceptionMessage('Wallet 7 has pending transfers.');

        $wallet->close(new DateTimeImmutable());
    }

    public function testCloseRejectsBlockedWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, blocked: true);

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 7 is blocked.');

        $wallet->close(new DateTimeImmutable());
    }

    public function testCloseRejectsAlreadyClosedWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, closed: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Wallet 7 is closed.');

        $wallet->close(new DateTimeImmutable());
    }

    public function testCreditRejectsClosedWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, closed: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Wallet 7 is closed.');

        $wallet->credit(Money::of('1.00', Currency::PLN));
    }

    public function testReserveRejectsClosedWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', closed: true);

        $this->expectException(LogicException::class);

        $wallet->reserve(Money::of('1.00', Currency::PLN));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Entity/WalletTest.php`
Expected: Errors — `Unknown named parameter $closedAt`, `Class "App\Exception\WalletNotEmptyException" not found` itp.

- [ ] **Step 3: Implement**

`src/Exception/WalletNotEmptyException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class WalletNotEmptyException extends RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet %d has a non-zero balance.', $walletId));
    }
}
```

`src/Exception/WalletHasPendingTransfersException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class WalletHasPendingTransfersException extends RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Wallet %d has pending transfers.', $walletId));
    }
}
```

`src/Entity/Wallet.php`:
- importy `use App\Exception\WalletHasPendingTransfersException;`, `use App\Exception\WalletNotEmptyException;`;
- konstruktor — nowy ostatni promowany parametr:

```php
        private readonly DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $closedAt = null,
    ) {
```

- gettery po `getCreatedAt()`:

```php
    public function getClosedAt(): ?DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function isClosed(): bool
    {
        return null !== $this->closedAt;
    }
```

- `credit()` i `reserve()` — po `$this->assertPositive($amount);` dodaj `$this->assertOpen();`;
- nowa metoda publiczna (po `settle()`):

```php
    /**
     * Closes an empty, unblocked wallet with nothing reserved. Pending transfers *to* the wallet are checked by
     * WalletService, which can see transactions.
     */
    public function close(DateTimeImmutable $at): void
    {
        $this->assertOpen();
        $this->assertNotBlocked();

        if (!$this->balance->equals(Money::zero($this->currency))) {
            throw new WalletNotEmptyException($this->id ?? 0);
        }

        if (!$this->reserved->equals(Money::zero($this->currency))) {
            throw new WalletHasPendingTransfersException($this->id ?? 0);
        }

        $this->closedAt = $at;
    }
```

- nowa metoda prywatna (obok `assertNotBlocked()`):

```php
    private function assertOpen(): void
    {
        if ($this->isClosed()) {
            throw new LogicException(sprintf('Wallet %d is closed.', $this->id ?? 0));
        }
    }
```

- [ ] **Step 4: Run to verify they pass, full suite**

Run: `php bin/phpunit tests/Entity/WalletTest.php && php bin/phpunit`
Expected: `OK`; pełny suite `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Exception/WalletNotEmptyException.php src/Exception/WalletHasPendingTransfersException.php src/Entity/Wallet.php tests/Support/WalletFixture.php tests/Entity/WalletTest.php
git commit -m "feat: add Wallet::close() for empty unblocked wallets

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Migracja, repozytoria, `hasInFlightTransfers()`

**Files:**
- Create: `migrations/Version20261009140000.php`, `tests/Integration/Repository/WalletClosingPersistenceTest.php`
- Modify: `src/Repository/WalletRepository.php`, `src/Repository/WalletRepositoryInterface.php`, `src/Repository/TransactionRepository.php`, `src/Repository/TransactionRepositoryInterface.php`, `tests/Integration/Repository/TransactionRepositoryTest.php`, `tests/Integration/TransferAtomicityTest.php`

**Interfaces:**
- Consumes: `Wallet::close()`, `getClosedAt()` (Task 1).
- Produces:
  - Kolumny `wallets.closed_at DATETIME NULL`, `wallets.open_flag` (wyliczana); indeks `wallet_user_currency_open_unique (user_id, currency, open_flag)`.
  - `WalletRepositoryInterface::findByUserId()` i `findByUserIdAndCurrency()` — **tylko otwarte**; `findById()` — dowolny.
  - `TransactionRepositoryInterface::hasInFlightTransfers(int $walletId): bool`.

- [ ] **Step 1: Write the failing tests**

`tests/Integration/Repository/WalletClosingPersistenceTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Repository\WalletRepository;
use App\Tests\Integration\DatabaseTestCase;
use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

class WalletClosingPersistenceTest extends DatabaseTestCase
{
    public function testPersistsClosedAt(): void
    {
        $wallet = $this->createWallet($this->createUser(), Currency::PLN);
        $wallet->close(new DateTimeImmutable('2026-10-09 12:00:00'));

        $repository = $this->service(WalletRepository::class);
        $repository->save($wallet);
        $reloaded = $repository->findById((int) $wallet->getId());

        self::assertTrue($reloaded?->isClosed());
        self::assertSame('2026-10-09 12:00:00', $reloaded?->getClosedAt()?->format('Y-m-d H:i:s'));
    }

    public function testFindersReturnOnlyOpenWallets(): void
    {
        $user = $this->createUser();
        $repository = $this->service(WalletRepository::class);
        $closed = $this->createWallet($user, Currency::PLN);
        $closed->close(new DateTimeImmutable());
        $repository->save($closed);
        $open = $this->createWallet($user, Currency::PLN);

        $byUser = $repository->findByUserId($user->getIdNotNull());

        self::assertSame([$open->getId()], array_map(static fn (Wallet $w): ?int => $w->getId(), $byUser));
        self::assertSame($open->getId(), $repository->findByUserIdAndCurrency($user->getIdNotNull(), Currency::PLN)?->getId());
        self::assertTrue($repository->findById((int) $closed->getId())?->isClosed());
    }

    public function testManyClosedWalletsInSameCurrencyAreAllowed(): void
    {
        $user = $this->createUser();
        $repository = $this->service(WalletRepository::class);

        foreach ([1, 2] as $ignored) {
            $wallet = $this->createWallet($user, Currency::EUR);
            $wallet->close(new DateTimeImmutable());
            $repository->save($wallet);
        }
        $this->createWallet($user, Currency::EUR);

        self::assertCount(1, $repository->findByUserId($user->getIdNotNull()));
    }

    public function testTwoOpenWalletsInSameCurrencyViolateUniqueness(): void
    {
        $user = $this->createUser();
        $this->createWallet($user, Currency::USD);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->createWallet($user, Currency::USD);
    }
}
```

`tests/Integration/Repository/TransactionRepositoryTest.php` — dodaj testy przed `private function createPendingTransaction`:

```php
    public function testHasInFlightTransfersForSourceAndTarget(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $transaction = $repository->findById($this->createPendingTransaction());

        self::assertTrue($repository->hasInFlightTransfers((int) $transaction?->getFromWalletId()));
        self::assertTrue($repository->hasInFlightTransfers((int) $transaction?->getToWalletId()));
    }

    public function testFraudReviewCountsAsInFlight(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $transaction = $repository->findById($this->createPendingTransaction(requiresAntiFraudCheck: true));

        self::assertTrue($repository->hasInFlightTransfers((int) $transaction?->getToWalletId()));
    }

    public function testSettledTransfersAreNotInFlight(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $transaction = $repository->findById($this->createPendingTransaction());
        $transaction?->setStatus(TransactionStatus::COMPLETED);
        $repository->save($transaction);

        self::assertFalse($repository->hasInFlightTransfers((int) $transaction?->getFromWalletId()));
        self::assertFalse($repository->hasInFlightTransfers((int) $transaction?->getToWalletId()));
    }

    public function testUnrelatedWalletHasNoInFlightTransfers(): void
    {
        $this->createPendingTransaction();
        $other = $this->createWallet($this->createUser(), Currency::GBP);

        self::assertFalse($this->service(TransactionRepository::class)->hasInFlightTransfers((int) $other->getId()));
    }
```

`tests/Integration/TransferAtomicityTest.php` — klasa anonimowa implementuje interfejs, więc dodaj do niej metodę (obok `findByStatus`):

```php
            public function hasInFlightTransfers(int $walletId): bool
            {
                return false;
            }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Integration`
Expected: FAIL/Error — `hasInFlightTransfers()` niezdefiniowane; `testPersistsClosedAt` failuje (brak kolumny `closed_at`); `testManyClosedWalletsInSameCurrencyAreAllowed` → `UniqueConstraintViolationException` (stary indeks).

- [ ] **Step 3: Migration**

`migrations/Version20261009140000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wallets.closed_at; one open wallet per user and currency';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` ADD `closed_at` DATETIME NULL AFTER `last_activity_at`');
        // 1 for open wallets, NULL for closed ones: NULLs never collide in a unique index (MariaDB has no partial indexes).
        $this->addSql('ALTER TABLE `wallets` ADD `open_flag` TINYINT AS (IF(`closed_at` IS NULL, 1, NULL)) PERSISTENT');
        // Add the new index before dropping the old one: fk_wallets_user_id needs an index starting with user_id.
        $this->addSql('ALTER TABLE `wallets` ADD UNIQUE KEY `wallet_user_currency_open_unique` (`user_id`, `currency`, `open_flag`)');
        $this->addSql('ALTER TABLE `wallets` DROP INDEX `wallet_user_currency_unique`');
    }

    public function down(Schema $schema): void
    {
        // Fails when a user has a closed and an open wallet in the same currency — reopening history is not reversible.
        $this->addSql('ALTER TABLE `wallets` ADD UNIQUE KEY `wallet_user_currency_unique` (`user_id`, `currency`)');
        $this->addSql('ALTER TABLE `wallets` DROP INDEX `wallet_user_currency_open_unique`');
        $this->addSql('ALTER TABLE `wallets` DROP `open_flag`');
        $this->addSql('ALTER TABLE `wallets` DROP `closed_at`');
    }
}
```

Run: `php bin/console doctrine:migrations:migrate -n && php bin/console doctrine:migrations:migrate -n --env=test`
Expected: oba `[OK] Successfully migrated to version: DoctrineMigrations\Version20261009140000`.

Round-trip (dev DB nie ma jeszcze zamkniętych portfeli, więc `down` przejdzie):
Run: `php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261009140000' --down -n && php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261009140000' --up -n && docker exec mariadb mariadb -uroot -proot app -e "SHOW INDEX FROM wallets WHERE Key_name LIKE 'wallet_user_currency%'"`
Expected: oba `[OK]`; indeks `wallet_user_currency_open_unique` na kolumnach `user_id`, `currency`, `open_flag`; brak `wallet_user_currency_unique`.

- [ ] **Step 4: Repositories**

`src/Repository/WalletRepositoryInterface.php` — docblocki:

```php
    /**
     * Open wallets only.
     *
     * @return Wallet[]
     */
    public function findByUserId(int $userId): array;

    /**
     * The user's open wallet in the currency, if any.
     */
    public function findByUserIdAndCurrency(int $userId, Currency $currency): ?Wallet;
```

`src/Repository/WalletRepository.php`:
- `findByUserId()` — po `->where('user_id = :user_id')` dodaj `->andWhere('closed_at IS NULL')`;
- `findByUserIdAndCurrency()` — po `->andWhere('currency = :currency')` dodaj `->andWhere('closed_at IS NULL')`;
- `buildEntity()` — po `createdAt: …` dodaj:

```php
            closedAt: null !== $row['closed_at'] ? new DateTimeImmutable($row['closed_at']) : null,
```

- `insert()` — w `values([...])` po `'created_at' => ':created_at',` dodaj `'closed_at' => ':closed_at',`, a w parametrach po `created_at`:

```php
                'closed_at' => $wallet->getClosedAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
```

- `update()` — po `->set('last_activity_at', ':last_activity_at')` dodaj `->set('closed_at', ':closed_at')` i parametr:

```php
                'closed_at' => $wallet->getClosedAt()?->setTimezone(timezone: new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
```

(Kolumna `open_flag` jest wyliczana — **nie** dodawaj jej do `insert`/`update`.)

`src/Repository/TransactionRepositoryInterface.php` — dodaj:

```php
    /**
     * Whether a pending or fraud-review transfer has the wallet as its source or target.
     */
    public function hasInFlightTransfers(int $walletId): bool;
```

`src/Repository/TransactionRepository.php` — po `findByStatus()`:

```php
    /**
     * @throws Exception
     */
    public function hasInFlightTransfers(int $walletId): bool
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->select('1')
            ->from(self::TABLE_NAME)
            ->where('(from_wallet_id = :wallet_id OR to_wallet_id = :wallet_id)')
            ->andWhere('status IN (:pending, :fraud_review)')
            ->setMaxResults(1);

        return false !== $this->connection->fetchOne($qb->getSQL(), [
            'wallet_id' => $walletId,
            'pending' => TransactionStatus::PENDING->value,
            'fraud_review' => TransactionStatus::FRAUD_REVIEW->value,
        ]);
    }
```

- [ ] **Step 5: Run to verify they pass, full suite**

Run: `php bin/phpunit tests/Integration && php bin/phpunit`
Expected: `OK`; pełny suite `OK` (zapis portfeli po migracji działa wszędzie — Review Focus #5).

- [ ] **Step 6: Commit**

```bash
git add migrations/Version20261009140000.php src/Repository tests/Integration
git commit -m "feat: persist wallet closing and allow one open wallet per currency

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `WalletService::closeWallet()` i mapowanie błędów

**Files:**
- Modify: `src/Service/WalletService.php`, `src/EventListener/ApiExceptionListener.php`
- Test: `tests/Service/WalletServiceTest.php`, `tests/EventListener/ApiExceptionListenerTest.php`

**Interfaces:**
- Consumes: `Wallet::close()`, `isClosed()`, wyjątki (Task 1); `hasInFlightTransfers()`, `lockForUpdate()`, `TransactionManagerInterface`.
- Produces: `new WalletService(WalletRepositoryInterface, TransactionRepositoryInterface, TransactionManagerInterface)`; `WalletService::closeWallet(int $userId, int $walletId): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/WalletServiceTest.php` — nowe importy:

```php
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransfersException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Tests\Support\ImmediateTransactionManager;
use App\Tests\Support\WalletFixture;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
```

pola i `setUp()`:

```php
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
```

nowe testy (przed zamykającą klamrą klasy):

```php
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
     * @param class-string<\Throwable> $exception
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
```

`tests/EventListener/ApiExceptionListenerTest.php` — importy `use App\Exception\WalletHasPendingTransfersException;`, `use App\Exception\WalletNotEmptyException;`; w `domainExceptionProvider()` dopisz:

```php
        yield 'not empty' => [new WalletNotEmptyException(3), 422];
        yield 'pending transfers' => [new WalletHasPendingTransfersException(3), 422];
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/WalletServiceTest.php tests/EventListener`
Expected: Errors (`WalletService::__construct()` dostaje za dużo argumentów / `closeWallet()` nie istnieje); listener — 2 przypadki bez odpowiedzi (`null` zamiast 422).

- [ ] **Step 3: Implement**

`src/Service/WalletService.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\WalletAlreadyExistsException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletHasPendingTransfersException;
use App\Exception\WalletNotEmptyException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use DateTimeImmutable;

readonly class WalletService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    public function createWallet(int $userId, Currency $currency): Wallet
    {
        $existing = $this->walletRepository->findByUserIdAndCurrency($userId, $currency);

        if (null !== $existing) {
            throw new WalletAlreadyExistsException($userId, $currency);
        }

        $wallet = Wallet::create($userId, $currency);
        $this->walletRepository->save($wallet);

        return $wallet;
    }

    /**
     * Closes (soft-deletes) an empty, unblocked wallet without pending transfers. Locks the wallet first, so a
     * concurrent transfer either completes its checks before the wallet is closed or sees it closed.
     *
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws WalletNotEmptyException
     * @throws WalletHasPendingTransfersException
     */
    public function closeWallet(int $userId, int $walletId): void
    {
        $this->transactionManager->transactional(function () use ($userId, $walletId): void {
            $this->walletRepository->lockForUpdate($walletId);

            $wallet = $this->walletRepository->findById($walletId);
            if (null === $wallet || $wallet->getUserId() !== $userId || $wallet->isClosed()) {
                throw new WalletNotFoundException($walletId);
            }

            if ($wallet->isBlocked()) {
                throw new WalletBlockedException($walletId);
            }

            if (!$wallet->getBalance()->equals(Money::zero($wallet->getCurrency()))) {
                throw new WalletNotEmptyException($walletId);
            }

            if ($this->transactionRepository->hasInFlightTransfers($walletId)) {
                throw new WalletHasPendingTransfersException($walletId);
            }

            $wallet->close(new DateTimeImmutable());
            $this->walletRepository->save($wallet);
        });
    }
}
```

`src/EventListener/ApiExceptionListener.php` — importy `use App\Exception\WalletHasPendingTransfersException;`, `use App\Exception\WalletNotEmptyException;`; w `DOMAIN_EXCEPTION_STATUS` po `InsufficientFundsException`:

```php
        WalletNotEmptyException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
        WalletHasPendingTransfersException::class => Response::HTTP_UNPROCESSABLE_ENTITY,
```

- [ ] **Step 4: Run to verify they pass, full suite**

Run: `php bin/phpunit tests/Service/WalletServiceTest.php tests/EventListener && php bin/console lint:container && php bin/phpunit`
Expected: `OK`; container OK; pełny suite `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/WalletService.php src/EventListener/ApiExceptionListener.php tests/Service/WalletServiceTest.php tests/EventListener/ApiExceptionListenerTest.php
git commit -m "feat: close empty wallets without pending transfers in WalletService

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Zamknięty portfel w przelewie, wpłacie i realizacji

**Files:**
- Modify: `src/Service/TransferService.php`, `src/Service/DepositService.php`, `src/Service/TransactionProcessorService.php`
- Test: `tests/Service/TransferServiceTest.php`, `tests/Service/DepositServiceTest.php`, `tests/Service/TransactionProcessorServiceTest.php`

**Interfaces:**
- Consumes: `Wallet::isClosed()`, `WalletFixture::create(..., closed: true)`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/TransferServiceTest.php` — dodaj przed `private function givenWallets`:

```php
    public function testTransferThrowsWhenSourceWalletIsClosed(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, closed: true), WalletFixture::create(2, 1, Currency::EUR));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '10.00');
    }

    public function testTransferThrowsWhenTargetWalletIsClosed(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00'), WalletFixture::create(2, 1, Currency::EUR, closed: true));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '10.00');
    }
```

`tests/Service/DepositServiceTest.php` — dodaj:

```php
    public function testDepositThrowsWhenWalletIsClosed(): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 1, Currency::PLN, closed: true));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->depositService->deposit(1, 1, '100.00');
    }
```

`tests/Service/TransactionProcessorServiceTest.php` — dodaj przed `private function givenWallets`:

```php
    public function testCompleteRejectsAndReleasesWhenTargetWalletClosed(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00');
        $toWallet = WalletFixture::create(2, 1, Currency::EUR, closed: true);
        $this->givenWallets($fromWallet, $toWallet);
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Service/TransferServiceTest.php tests/Service/DepositServiceTest.php tests/Service/TransactionProcessorServiceTest.php`
Expected: FAIL — przelew/wpłata na zamknięty portfel dochodzi do `reserve()`/`credit()` (`LogicException: Wallet … is closed.`) zamiast `WalletNotFoundException`; `complete()` na zamknięty cel → `LogicException` z `credit()`.

- [ ] **Step 3: Implement**

`src/Service/TransferService.php` — w `placeTransfer()`:

```php
        $fromWallet = $this->walletRepository->findById($fromWalletId);
        if (null === $fromWallet || $fromWallet->getUserId() !== $userId || $fromWallet->isClosed()) {
            throw new WalletNotFoundException($fromWalletId);
        }

        $toWallet = $this->walletRepository->findById($toWalletId);
        if (null === $toWallet || $toWallet->getUserId() !== $userId || $toWallet->isClosed()) {
            throw new WalletNotFoundException($toWalletId);
        }
```

`src/Service/DepositService.php`:

```php
            if (null === $wallet || $wallet->getUserId() !== $userId || $wallet->isClosed()) {
                throw new WalletNotFoundException($walletId);
            }
```

`src/Service/TransactionProcessorService.php` — w `complete()` warunek odrzucenia:

```php
            if (null === $fromWallet || null === $toWallet
                || $fromWallet->isBlocked() || $toWallet->isBlocked()
                || $fromWallet->isClosed() || $toWallet->isClosed()) {
```

(Jeśli w pliku warunek wygląda inaczej po wcześniejszych poprawkach — np. obsługa portfela źródłowego jako celu — dopisz tylko `|| $fromWallet->isClosed() || $toWallet->isClosed()` do istniejącego warunku, nie zmieniając reszty.)

- [ ] **Step 4: Run to verify they pass, full suite**

Run: `php bin/phpunit tests/Service && php bin/phpunit`
Expected: `OK`; pełny suite `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/TransferService.php src/Service/DepositService.php src/Service/TransactionProcessorService.php tests/Service/TransferServiceTest.php tests/Service/DepositServiceTest.php tests/Service/TransactionProcessorServiceTest.php
git commit -m "feat: treat closed wallets as missing in transfers, deposits and processing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Endpoint `DELETE /api/wallets/{id}`

**Files:**
- Modify: `src/Controller/WalletController.php`
- Create: `tests/Functional/WalletClosingApiTest.php`

**Interfaces:**
- Consumes: `WalletService::closeWallet()`, `ApiTestCase` (`createUserWithToken()`, `createWallet()`, `sendJson()`).

- [ ] **Step 1: Write the failing tests**

`tests/Functional/WalletClosingApiTest.php`:

```php
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
```

- [ ] **Step 2: Run to verify they fail**

Run: `php bin/phpunit tests/Functional/WalletClosingApiTest.php`
Expected: FAIL — `DELETE /api/wallets/{id}` → 405 (`Method Not Allowed`) zamiast 204/404/422 (poza `testNonNumericIdIsNotFound`, `testCloseUnknownWallet`/inne mogą dać 405).

- [ ] **Step 3: Implement**

`src/Controller/WalletController.php` — po akcji `deposit()`:

```php
    #[Route('/{id}', requirements: ['id' => '\d{1,18}'], methods: ['DELETE'])]
    public function close(int $id, #[CurrentUser] User $user): Response
    {
        $this->walletService->closeWallet($user->getIdNotNull(), $id);

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
```

- [ ] **Step 4: Run to verify they pass, full suite, contract untouched**

Run: `php bin/phpunit tests/Functional && php bin/phpunit && git diff --quiet 27c3b3e -- tests/Functional/WalletApiContractTest.php && echo "contract unchanged"`
Expected: `OK`; pełny suite `OK`; `contract unchanged`.

- [ ] **Step 5: Verify live**

```bash
TOKEN=$(php bin/console app:create-user | grep -oE '[a-f0-9]{64}' | tail -1); A="Authorization: Bearer $TOKEN"; J="Content-Type: application/json"; U=https://127.0.0.1:8000/api/wallets
W=$(curl -sk -X POST -H "$A" -H "$J" -d '{"currency":"PLN"}' $U | grep -oE '"id":[0-9]+' | cut -d: -f2)
curl -sk -X DELETE -H "$A" $U/$W -w '%{http_code}\n'
curl -sk -X DELETE -H "$A" $U/$W -w ' %{http_code}\n'
curl -sk -H "$A" $U; echo
curl -sk -X POST -H "$A" -H "$J" -d '{"currency":"PLN"}' $U -w ' %{http_code}\n'
```

Expected: `204`; `{"error":"Wallet <W> not found."} 404`; `[]`; nowy portfel PLN `… 201`.

- [ ] **Step 6: Commit**

```bash
git add src/Controller/WalletController.php tests/Functional/WalletClosingApiTest.php
git commit -m "feat: add DELETE /api/wallets/{id} closing endpoint

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Dokumentacja, Postman, styl

**Files:**
- Modify: `docs/business-logic.md`, `README.md`, `exchange-api.postman_collection.json`

- [ ] **Step 1: Business logic doc**

`docs/business-logic.md` — po sekcji `## Deposits — …` (przed `## Limits`) wstaw:

```markdown
## Closing a wallet — `DELETE /api/wallets/{id}`

Closing is a soft delete: the wallet disappears from the API but stays in the database with its full
transaction history. Only an empty wallet can be closed, so no money is ever lost and every transfer can still
be settled.

| Check (in this order)                                              | Response                                   |
|--------------------------------------------------------------------|--------------------------------------------|
| wallet exists, belongs to the user and is not closed yet           | `404` Wallet {id} not found.               |
| wallet is not blocked                                              | `422` Wallet {id} is blocked.              |
| balance is exactly zero                                            | `422` Wallet {id} has a non-zero balance.  |
| no `pending` / `fraud_review` transfer from or to the wallet        | `422` Wallet {id} has pending transfers.   |

On success the API returns `204`. A closed wallet behaves like one that does not exist: it is not listed,
deposits and transfers from or to it get `404`, and the user can open a new wallet in the same currency.
```

- [ ] **Step 2: README**

`README.md` — w tabeli endpointów po wierszu `POST /api/wallets/{id}/deposit` dodaj:

```markdown
| `DELETE` | `/api/wallets/{id}`       | Close (soft-delete) an empty wallet. Returns `204`. Returns `422` if the wallet is blocked, has a non-zero balance or pending transfers. A closed wallet is no longer listed and behaves as not found. |
```

- [ ] **Step 3: Postman**

Run:

```bash
python3 - <<'PY'
import json
path = 'exchange-api.postman_collection.json'
with open(path) as f:
    collection = json.load(f)
wallets = next(group for group in collection['item'] if group['name'] == 'Wallets')
wallets['item'].append({
    'name': 'Close Wallet',
    'request': {
        'method': 'DELETE',
        'header': [],
        'url': {
            'raw': '{{baseUrl}}/api/wallets/1',
            'host': ['{{baseUrl}}'],
            'path': ['api', 'wallets', '1'],
        },
        'description': 'Closes (soft-deletes) an empty wallet. Returns 204; 422 if the wallet is blocked, has a non-zero balance or pending transfers.',
    },
    'response': [],
})
with open(path, 'w') as f:
    json.dump(collection, f, indent=2, ensure_ascii=False)
    f.write('\n')
PY
python3 -c "import json; d=json.load(open('exchange-api.postman_collection.json')); print([i['name'] for g in d['item'] for i in g['item']])"
```

Expected: lista zawiera `Close Wallet`. Sprawdź `git diff --stat exchange-api.postman_collection.json` — jeśli zmiana formatowania (wcięcia) objęła cały plik, przywróć oryginalne wcięcie (`git diff` ma pokazywać tylko nowy element); dopasuj `indent=` do formatu pliku.

- [ ] **Step 4: Style, full suite**

Run:

```bash
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --path-mode=intersection --dry-run --diff $(git diff --name-only --diff-filter=AM 2c0ac22 -- src tests migrations)
php bin/phpunit
```

Expected: cs-fixer bez diffów (jeśli są — `fix` bez `--dry-run --diff`, ponowny `php bin/phpunit`, dołącz do commita); pełny suite `OK`.

- [ ] **Step 5: Commit**

```bash
git add docs/business-logic.md README.md exchange-api.postman_collection.json
git commit -m "docs: describe wallet closing in business logic, README and Postman

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
