# Transfer Reservation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Przelew rezerwuje środki zamiast od razu przesuwać salda; realizacja rozlicza rezerwację, uznaje cel i księguje spread firmie; odrzucenie zwalnia rezerwację — wszystko w transakcjach DB z blokadami wierszy, z limitami per waluta.

**Architecture:** Reguły sald żyją w encji `Wallet` (`reserve`/`release`/`settle`/`credit`, pole `reserved`). Serwisy (`TransferService`, `DepositService`, `TransactionProcessorService`) wykonują każdą operację w `TransactionManagerInterface::transactional()` i blokują portfele przez `WalletRepositoryInterface::lockForUpdate()` w rosnącej kolejności `id`. Jednokrotne rozliczenie transakcji gwarantuje warunkowy `UPDATE` statusu w `TransactionRepository`. Limity per waluta w `TransactionLimits` z parametrów `config/services.yaml`.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine DBAL 4 (+ Migrations), MariaDB 11.4, PHPUnit 13, `BcMath\Number` przez `App\ValueObject\Money`.

**Spec:** `docs/superpowers/specs/2026-10-09-transfer-reservation-design.md`

## Global Constraints

- Model rezerwacji: `available = balance − reserved`; niezmiennik `0 ≤ reserved ≤ balance` (poza danymi historycznymi z ujemnym saldem — nie naprawiamy ich).
- Efekty: `transfer` → źródło `reserved += fromAmount`; `complete` → źródło `balance −= fromAmount`, `reserved −= fromAmount`, cel `balance += toAmount`, firma `+= spread` (waluta celu); `reject` → źródło `reserved −= fromAmount`; `deposit` → `balance += amount`.
- Zablokowany portfel docelowy nie przyjmuje przelewów — ani przy złożeniu (`422`), ani przy realizacji (transakcja → `REJECTED`).
- Próg anti-fraud: `fromAmount > antiFraudThreshold(fromCurrency)` (ostra nierówność, waluta **źródłowa**).
- Limit wpłaty: `amount > depositLimit(currency)` → `400` `Amount cannot exceed {limit} {CUR}.` (np. `Amount cannot exceed 2500.00 EUR.`).
- Wartości limitów (`config/services.yaml`):

  | Waluta | anti-fraud | wpłata  |
  |--------|-----------:|--------:|
  | PLN    | 15000      | 10000   |
  | EUR    | 3500       | 2500    |
  | USD    | 4000       | 2500    |
  | GBP    | 3000       | 2000    |
  | CHF    | 3200       | 2000    |
  | JPY    | 650000     | 450000  |
  | HUF    | 1250000    | 850000  |

- Komunikaty błędów (dokładnie): `Cannot transfer to the same wallet.` (400), `Insufficient funds in wallet {id}.` (422), `Wallet {id} is blocked.` (422), `Wallet {id} not found.` (404), `Transaction {id} was already processed.`
- Kwoty operacji na portfelu muszą być dodatnie (`InvalidArgumentException('Amount must be positive.')`) i w walucie portfela (`CurrencyMismatchException`).
- Każda operacja zmieniająca salda — jedna transakcja DB; blokady portfeli przed odczytem, rosnąco po `id`.
- Styl: `declare(strict_types=1)`, `@Symfony` php-cs-fixer z importami klas, asercje jak w otaczającym pliku.
- Commity: tylko pliki z taska; **nigdy** `.env` (lokalne zmiany użytkownika). Każdy commit kończy się `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Serwer aplikacji użytkownika działa na `https://127.0.0.1:8000` (`curl -k`) — używaj go do testów na żywo, nie uruchamiaj ani nie zabijaj żadnych serwerów.
- `cp` w powłoce użytkownika to alias `cp -i` — przy nadpisywaniu używaj `command cp -f`.

## Stan wyjściowy testów

Na `main` (`9aeef05`): `php bin/phpunit` → 186 testów, **2 failures**: `TransactionProcessorServiceTest::testRejectSetsRejectedStatus`, `TransferServiceTest::testTransferSuccessfully`. Po Tasku 5 drugi przechodzi, po Tasku 6 pierwszy. Kryterium końcowe: **0 failures**.

Pośrednie taski uruchamiają tylko wskazane testy — pełny suite może być czerwony między Taskiem 4 a 6 (np. `TransferService` jeszcze bez nowego konstruktora w kontenerze).

## Review Focus

1. **Dwa przeciwne przelewy naraz (A→B i B→A)** — oczekiwane: brak deadlocka, bo blokady zawsze rosnąco po `id` i bez duplikatów. Test: Task 3, `WalletRepositoryLockTest::testLocksDistinctIdsInAscendingOrder` (mock `Connection`).
2. **Portfel z historycznym ujemnym saldem** (skutek starego błędu, np. `-995.00`) próbuje przelewu — oczekiwane: `422 Insufficient funds`, nie wyjątek z `Money`. Testy: Task 2 `WalletTest::testReserveFailsOnLegacyNegativeBalance`, Task 5 `testTransferFailsFromLegacyNegativeBalance`.
3. **Portfel zablokowany między złożeniem a realizacją** (fraud review trwa) — oczekiwane: `complete` → `REJECTED` z zwolnioną rezerwacją, bez księgowania spreadu. Testy: Task 6 `testCompleteRejectsAndReleasesWhenSourceWalletBlocked` / `...TargetWalletBlocked`.
4. **Wartości graniczne** — przelew dokładnie całej dostępnej kwoty przechodzi; kwota równa progowi anti-fraud nie trafia do review; wpłata równa limitowi (także JPY bez groszy) przechodzi, +1 jednostka odrzucona. Testy: Task 4 `DepositServiceTest` (providery), Task 5 `testTransferOfExactlyAvailableAmountSucceeds`, `antiFraudProvider`.
5. **Transakcja przetworzona dwa razy** (stara kopia obiektu, dwa równoległe `app:process-transactions`) — oczekiwane: jedno rozliczenie, druga próba cofnięta, komenda idzie dalej. Testy: Task 3 `TransactionRepositoryTest::testSecondStatusChangeFromStaleCopyIsRefused`, Task 6 `ProcessTransactionsCommandTest`, Task 7 `TransferFlowTest::testTransactionCannotBeSettledTwice`.

---

## File Structure

**Create:**
- `src/Service/TransactionLimits.php` — limity per waluta (anti-fraud, wpłata).
- `src/Repository/TransactionManagerInterface.php`, `src/Repository/DbalTransactionManager.php` — granica transakcji DB.
- `src/Exception/InsufficientFundsException.php`, `SameWalletTransferException.php`, `DepositLimitExceededException.php`, `TransactionAlreadyProcessedException.php`.
- `migrations/Version20261009130000.php` — kolumna `wallets.reserved` + przeniesienie transakcji w locie.
- `docker/mariadb/init/01-test-database.sql` — baza `app_test` i uprawnienia dla `app`.
- `docs/business-logic.md` — trwały opis logiki.
- Testy: `tests/Support/{ImmediateTransactionManager,WalletFixture,TransactionLimitsFactory}.php`, `tests/Integration/{DatabaseTestCase,TransactionLimitsWiringTest,TransferFlowTest}.php`, `tests/Integration/Repository/{DbalTransactionManagerTest,TransactionRepositoryTest,WalletRepositoryReservedTest}.php`, `tests/Repository/WalletRepositoryLockTest.php`, `tests/Service/TransactionLimitsTest.php`, `tests/Command/ProcessTransactionsCommandTest.php`.

**Modify:**
- `src/Entity/Wallet.php` (reserved + operacje, potem bez `setBalance`), `src/Entity/CompanyWallet.php` (`credit` zamiast `setBalance`).
- `src/Repository/WalletRepository{,Interface}.php` (`reserved`, `lockForUpdate`), `src/Repository/TransactionRepository.php` (warunkowy update).
- `src/Service/{Transfer,Deposit,TransactionProcessor}Service.php`, `src/Controller/WalletController.php`, `src/Command/ProcessTransactionsCommand.php`, `src/Dto/WalletResponse.php`.
- `config/services.yaml`, `docker-compose.yml`, `README.md`.
- Testy istniejące: `tests/Entity/{Wallet,CompanyWallet}Test.php`, `tests/Dto/WalletResponseTest.php`, `tests/Service/{Transfer,Deposit,TransactionProcessor}ServiceTest.php`, `tests/Controller/WalletControllerTest.php`.

---

### Task 1: `TransactionLimits` — limity per waluta

**Files:**
- Create: `src/Service/TransactionLimits.php`, `tests/Service/TransactionLimitsTest.php`, `tests/Support/TransactionLimitsFactory.php`
- Modify: `config/services.yaml`

**Interfaces:**
- Produces:
  - `new TransactionLimits(array $antiFraudThresholds, array $depositLimits)` — klucze: kody walut (`'PLN'`…), wartości: stringi dziesiętne; brak waluty → `InvalidArgumentException('Missing {name} for {CUR}.')`, wartość ≤ 0 → `InvalidArgumentException('{Name} for {CUR} must be positive.')`, zbyt precyzyjna → `InvalidMoneyAmountException`.
  - `TransactionLimits::antiFraudThreshold(Currency): Money`, `TransactionLimits::depositLimit(Currency): Money`.
  - `App\Tests\Support\TransactionLimitsFactory::default(): TransactionLimits` — wartości z Global Constraints.

- [ ] **Step 1: Write the failing test**

`tests/Support/TransactionLimitsFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\TransactionLimits;

final class TransactionLimitsFactory
{
    public const array ANTI_FRAUD_THRESHOLDS = [
        'PLN' => '15000',
        'EUR' => '3500',
        'USD' => '4000',
        'GBP' => '3000',
        'CHF' => '3200',
        'JPY' => '650000',
        'HUF' => '1250000',
    ];

    public const array DEPOSIT_LIMITS = [
        'PLN' => '10000',
        'EUR' => '2500',
        'USD' => '2500',
        'GBP' => '2000',
        'CHF' => '2000',
        'JPY' => '450000',
        'HUF' => '850000',
    ];

    public static function default(): TransactionLimits
    {
        return new TransactionLimits(self::ANTI_FRAUD_THRESHOLDS, self::DEPOSIT_LIMITS);
    }
}
```

`tests/Service/TransactionLimitsTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Exception\InvalidMoneyAmountException;
use App\Service\TransactionLimits;
use App\Tests\Support\TransactionLimitsFactory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TransactionLimitsTest extends TestCase
{
    public function testReturnsLimitsPerCurrency(): void
    {
        $limits = TransactionLimitsFactory::default();

        self::assertSame('2500.00', $limits->depositLimit(Currency::EUR)->toString());
        self::assertSame(Currency::EUR, $limits->depositLimit(Currency::EUR)->getCurrency());
        self::assertSame('10000.00', $limits->depositLimit(Currency::PLN)->toString());
        self::assertSame('450000', $limits->depositLimit(Currency::JPY)->toString());
        self::assertSame('15000.00', $limits->antiFraudThreshold(Currency::PLN)->toString());
        self::assertSame('650000', $limits->antiFraudThreshold(Currency::JPY)->toString());
        self::assertSame(Currency::HUF, $limits->antiFraudThreshold(Currency::HUF)->getCurrency());
    }

    public function testRejectsMissingCurrency(): void
    {
        $depositLimits = TransactionLimitsFactory::DEPOSIT_LIMITS;
        unset($depositLimits['HUF']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing deposit limit for HUF.');

        new TransactionLimits(TransactionLimitsFactory::ANTI_FRAUD_THRESHOLDS, $depositLimits);
    }

    public function testRejectsNonPositiveValue(): void
    {
        $thresholds = ['PLN' => '0'] + TransactionLimitsFactory::ANTI_FRAUD_THRESHOLDS;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Anti-fraud threshold for PLN must be positive.');

        new TransactionLimits($thresholds, TransactionLimitsFactory::DEPOSIT_LIMITS);
    }

    public function testRejectsValueTooPreciseForCurrency(): void
    {
        $depositLimits = ['JPY' => '100.5'] + TransactionLimitsFactory::DEPOSIT_LIMITS;

        $this->expectException(InvalidMoneyAmountException::class);

        new TransactionLimits(TransactionLimitsFactory::ANTI_FRAUD_THRESHOLDS, $depositLimits);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Service/TransactionLimitsTest.php`
Expected: Errors `Class "App\Service\TransactionLimits" not found`.

- [ ] **Step 3: Implement**

`src/Service/TransactionLimits.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\ValueObject\Money;
use InvalidArgumentException;

final readonly class TransactionLimits
{
    /** @var array<string, Money> */
    private array $antiFraudThresholds;

    /** @var array<string, Money> */
    private array $depositLimits;

    /**
     * @param array<string, string|int> $antiFraudThresholds amount per currency code; a transfer above it goes to fraud review
     * @param array<string, string|int> $depositLimits       maximum single deposit per currency code
     */
    public function __construct(array $antiFraudThresholds, array $depositLimits)
    {
        $this->antiFraudThresholds = self::toMoneyMap($antiFraudThresholds, 'anti-fraud threshold');
        $this->depositLimits = self::toMoneyMap($depositLimits, 'deposit limit');
    }

    public function antiFraudThreshold(Currency $currency): Money
    {
        return $this->antiFraudThresholds[$currency->value];
    }

    public function depositLimit(Currency $currency): Money
    {
        return $this->depositLimits[$currency->value];
    }

    /**
     * @param array<string, string|int> $values
     *
     * @return array<string, Money>
     */
    private static function toMoneyMap(array $values, string $name): array
    {
        $map = [];

        foreach (Currency::cases() as $currency) {
            if (!isset($values[$currency->value])) {
                throw new InvalidArgumentException(sprintf('Missing %s for %s.', $name, $currency->value));
            }

            $limit = Money::of((string) $values[$currency->value], $currency);
            if (!$limit->isGreaterThan(Money::zero($currency))) {
                throw new InvalidArgumentException(sprintf('%s for %s must be positive.', ucfirst($name), $currency->value));
            }

            $map[$currency->value] = $limit;
        }

        return $map;
    }
}
```

`config/services.yaml` — zastąp całą zawartość:

```yaml
parameters:
  # Per-currency limits (amounts in the given currency).
  # A transfer whose source amount is greater than the threshold goes to manual fraud review.
  app.limits.anti_fraud_threshold:
    PLN: '15000'
    EUR: '3500'
    USD: '4000'
    GBP: '3000'
    CHF: '3200'
    JPY: '650000'
    HUF: '1250000'
  # Maximum amount of a single deposit.
  app.limits.deposit:
    PLN: '10000'
    EUR: '2500'
    USD: '2500'
    GBP: '2000'
    CHF: '2000'
    JPY: '450000'
    HUF: '850000'

services:
  _defaults:
    autowire: true
    autoconfigure: true

  App\:
    resource: '../src/'

  App\Service\TransactionLimits:
    arguments:
      $antiFraudThresholds: '%app.limits.anti_fraud_threshold%'
      $depositLimits: '%app.limits.deposit%'
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service/TransactionLimitsTest.php && php bin/console lint:container`
Expected: `OK (4 tests, …)`; `[OK] The container was linted successfully`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/TransactionLimits.php config/services.yaml tests/Service/TransactionLimitsTest.php tests/Support/TransactionLimitsFactory.php
git commit -m "feat: add per-currency transaction limits

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Rezerwacja na `Wallet` — encja, kolumna, repozytorium, DTO

**Files:**
- Create: `src/Exception/InsufficientFundsException.php`, `migrations/Version20261009130000.php`, `tests/Support/WalletFixture.php`
- Modify: `src/Entity/Wallet.php`, `src/Repository/WalletRepository.php`, `src/Dto/WalletResponse.php`
- Test: `tests/Entity/WalletTest.php`, `tests/Dto/WalletResponseTest.php`

**Interfaces:**
- Consumes: `Money`, `CurrencyMismatchException`, `WalletBlockedException(int $walletId)`.
- Produces:
  - `Wallet::__construct(?int $id, int $userId, Currency $currency, Money $balance, Money $reserved, bool $isBlocked, ?DateTimeImmutable $lastActivityAt, DateTimeImmutable $createdAt)`.
  - `Wallet::getReserved(): Money`, `getAvailable(): Money`, `credit(Money): void`, `reserve(Money): void`, `release(Money): void`, `settle(Money): void`. (`setBalance` zostaje do Taska 6.)
  - `InsufficientFundsException(int $walletId)` — `Insufficient funds in wallet {id}.`
  - `App\Tests\Support\WalletFixture::create(int $id, int $userId, Currency $currency, string $balance = '0', string $reserved = '0', bool $blocked = false): Wallet`.
  - `WalletResponse` → dodatkowo `reserved`, `available` (stringi).
  - Kolumna `wallets.reserved DECIMAL(15,4) NOT NULL DEFAULT 0`.

- [ ] **Step 1: Write the failing tests**

`tests/Support/WalletFixture.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Wallet;
use App\Enum\Currency;
use App\ValueObject\Money;
use DateTimeImmutable;

final class WalletFixture
{
    /**
     * Builds a persisted-looking wallet (with id) in any state, bypassing domain operations.
     */
    public static function create(
        int $id,
        int $userId,
        Currency $currency,
        string $balance = '0',
        string $reserved = '0',
        bool $blocked = false,
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
        );
    }
}
```

`tests/Entity/WalletTest.php` — dodaj importy:

```php
use App\Exception\InsufficientFundsException;
use App\Exception\WalletBlockedException;
use App\Tests\Support\WalletFixture;
use Generator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
```

w `testConstructorRejectsBalanceInDifferentCurrency` dodaj argument po `balance:`:

```php
            reserved: Money::zero(Currency::PLN),
```

i dopisz testy przed zamykającą klamrą klasy:

```php
    public function testCreateStartsWithNothingReserved(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);

        $this->assertSame('0.00', $wallet->getReserved()->toString());
        $this->assertSame('0.00', $wallet->getAvailable()->toString());
    }

    public function testConstructorRejectsReservedInDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        new Wallet(
            id: 1,
            userId: 1,
            currency: Currency::PLN,
            balance: Money::zero(Currency::PLN),
            reserved: Money::zero(Currency::EUR),
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function testCreditIncreasesBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '10.00');

        $wallet->credit(Money::of('2.50', Currency::PLN));

        $this->assertSame('12.50', $wallet->getBalance()->toString());
        $this->assertSame('12.50', $wallet->getAvailable()->toString());
    }

    public function testCreditRejectsBlockedWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, blocked: true);

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 7 is blocked.');

        $wallet->credit(Money::of('1.00', Currency::PLN));
    }

    public function testReserveReducesAvailableButNotBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00');

        $wallet->reserve(Money::of('30.00', Currency::PLN));

        $this->assertSame('100.00', $wallet->getBalance()->toString());
        $this->assertSame('30.00', $wallet->getReserved()->toString());
        $this->assertSame('70.00', $wallet->getAvailable()->toString());
    }

    public function testReserveWholeAvailableAmount(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '60.00');

        $wallet->reserve(Money::of('40.00', Currency::PLN));

        $this->assertSame('0.00', $wallet->getAvailable()->toString());
    }

    public function testReserveRejectsMoreThanAvailable(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '60.00');

        try {
            $wallet->reserve(Money::of('40.01', Currency::PLN));
            $this->fail('Expected InsufficientFundsException.');
        } catch (InsufficientFundsException $e) {
            $this->assertSame('Insufficient funds in wallet 7.', $e->getMessage());
        }

        $this->assertSame('60.00', $wallet->getReserved()->toString());
    }

    public function testReserveFailsOnLegacyNegativeBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '-995.00');

        $this->expectException(InsufficientFundsException::class);

        $wallet->reserve(Money::of('1.00', Currency::PLN));
    }

    public function testReserveRejectsBlockedWallet(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', blocked: true);

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 7 is blocked.');

        $wallet->reserve(Money::of('1.00', Currency::PLN));
    }

    public function testReleaseReturnsReservationToAvailable(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $wallet->release(Money::of('30.00', Currency::PLN));

        $this->assertSame('100.00', $wallet->getBalance()->toString());
        $this->assertSame('0.00', $wallet->getReserved()->toString());
    }

    public function testReleaseRejectsMoreThanReserved(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot use 30.01 PLN: only 30.00 reserved.');

        $wallet->release(Money::of('30.01', Currency::PLN));
    }

    public function testSettleTakesReservedAmountOutOfBalance(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $wallet->settle(Money::of('30.00', Currency::PLN));

        $this->assertSame('70.00', $wallet->getBalance()->toString());
        $this->assertSame('0.00', $wallet->getReserved()->toString());
        $this->assertSame('70.00', $wallet->getAvailable()->toString());
    }

    public function testSettleRejectsMoreThanReserved(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(LogicException::class);

        $wallet->settle(Money::of('30.01', Currency::PLN));
    }

    public function testSettleIgnoresBlockade(): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00', blocked: true);

        $wallet->settle(Money::of('30.00', Currency::PLN));

        $this->assertSame('70.00', $wallet->getBalance()->toString());
    }

    #[DataProvider('operationProvider')]
    public function testOperationsRejectNonPositiveAmount(string $operation): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive.');

        $wallet->{$operation}(Money::zero(Currency::PLN));
    }

    #[DataProvider('operationProvider')]
    public function testOperationsRejectNegativeAmount(string $operation): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(InvalidArgumentException::class);

        $wallet->{$operation}(Money::of('-1.00', Currency::PLN));
    }

    #[DataProvider('operationProvider')]
    public function testOperationsRejectOtherCurrency(string $operation): void
    {
        $wallet = WalletFixture::create(7, 1, Currency::PLN, '100.00', '30.00');

        $this->expectException(CurrencyMismatchException::class);

        $wallet->{$operation}(Money::of('1.00', Currency::EUR));
    }

    public static function operationProvider(): Generator
    {
        yield 'credit' => ['credit'];
        yield 'reserve' => ['reserve'];
        yield 'release' => ['release'];
        yield 'settle' => ['settle'];
    }
```

`tests/Dto/WalletResponseTest.php` — w `testJsonSerializeWithoutLastActivityAt` po asercji `balance` dodaj:

```php
        self::assertSame('0.00', $data['reserved']);
        self::assertSame('0.00', $data['available']);
```

i dopisz test (import `use App\Tests\Support\WalletFixture;`):

```php
    public function testJsonSerializeReservedAndAvailable(): void
    {
        $wallet = WalletFixture::create(3, 1, Currency::PLN, '100.00', '30.00');

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertSame('100.00', $data['balance']);
        self::assertSame('30.00', $data['reserved']);
        self::assertSame('70.00', $data['available']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Entity/WalletTest.php tests/Dto/WalletResponseTest.php`
Expected: Errors — `Unknown named parameter $reserved`, `Call to undefined method App\Entity\Wallet::credit()` itp.

- [ ] **Step 3: Implement exception and entity**

`src/Exception/InsufficientFundsException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class InsufficientFundsException extends RuntimeException
{
    public function __construct(int $walletId)
    {
        parent::__construct(sprintf('Insufficient funds in wallet %d.', $walletId));
    }
}
```

`src/Entity/Wallet.php` — zastąp całą zawartość (`setBalance` zostaje do Taska 6):

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\Exception\InsufficientFundsException;
use App\Exception\WalletBlockedException;
use App\ValueObject\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use LogicException;

class Wallet
{
    /**
     * @param Money $reserved sum of outgoing transfers waiting for processing; available = balance − reserved
     */
    public function __construct(
        private ?int $id,
        private readonly int $userId,
        private readonly Currency $currency,
        private Money $balance,
        private Money $reserved,
        private bool $isBlocked,
        private ?DateTimeImmutable $lastActivityAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
        $this->assertCurrency($balance);
        $this->assertCurrency($reserved);
    }

    public static function create(int $userId, Currency $currency): self
    {
        return new self(
            id: null,
            userId: $userId,
            currency: $currency,
            balance: Money::zero($currency),
            reserved: Money::zero($currency),
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function getBalance(): Money
    {
        return $this->balance;
    }

    public function getReserved(): Money
    {
        return $this->reserved;
    }

    public function getAvailable(): Money
    {
        return $this->balance->subtract($this->reserved);
    }

    public function isBlocked(): bool
    {
        return $this->isBlocked;
    }

    public function getLastActivityAt(): ?DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setBalance(Money $balance): void
    {
        $this->assertCurrency($balance);
        $this->balance = $balance;
    }

    public function setIsBlocked(bool $isBlocked): void
    {
        $this->isBlocked = $isBlocked;
    }

    public function setLastActivityAt(?DateTimeImmutable $lastActivityAt): void
    {
        $this->lastActivityAt = $lastActivityAt;
    }

    /**
     * Adds money to the balance (deposit or incoming transfer).
     */
    public function credit(Money $amount): void
    {
        $this->assertPositive($amount);
        $this->assertNotBlocked();

        $this->balance = $this->balance->add($amount);
    }

    /**
     * Blocks money for an outgoing transfer until it is settled or released.
     */
    public function reserve(Money $amount): void
    {
        $this->assertPositive($amount);
        $this->assertNotBlocked();

        if ($amount->isGreaterThan($this->getAvailable())) {
            throw new InsufficientFundsException($this->id ?? 0);
        }

        $this->reserved = $this->reserved->add($amount);
    }

    /**
     * Gives reserved money back to the available pool (rejected transfer).
     */
    public function release(Money $amount): void
    {
        $this->assertPositive($amount);
        $this->assertReserved($amount);

        $this->reserved = $this->reserved->subtract($amount);
    }

    /**
     * Takes reserved money out of the wallet (completed transfer). Works on a blocked wallet on purpose:
     * callers decide whether a blocked wallet may settle.
     */
    public function settle(Money $amount): void
    {
        $this->assertPositive($amount);
        $this->assertReserved($amount);

        $this->balance = $this->balance->subtract($amount);
        $this->reserved = $this->reserved->subtract($amount);
    }

    private function assertCurrency(Money $money): void
    {
        if ($money->getCurrency() !== $this->currency) {
            throw new CurrencyMismatchException($this->currency, $money->getCurrency());
        }
    }

    private function assertPositive(Money $amount): void
    {
        $this->assertCurrency($amount);

        if (!$amount->isGreaterThan(Money::zero($this->currency))) {
            throw new InvalidArgumentException('Amount must be positive.');
        }
    }

    private function assertNotBlocked(): void
    {
        if ($this->isBlocked) {
            throw new WalletBlockedException($this->id ?? 0);
        }
    }

    private function assertReserved(Money $amount): void
    {
        if ($amount->isGreaterThan($this->reserved)) {
            throw new LogicException(sprintf(
                'Cannot use %s %s: only %s reserved.',
                $amount->toString(),
                $this->currency->value,
                $this->reserved->toString(),
            ));
        }
    }
}
```

`src/Dto/WalletResponse.php` — w `jsonSerialize()` po `balance`:

```php
            'balance' => $this->wallet->getBalance()->toString(),
            'reserved' => $this->wallet->getReserved()->toString(),
            'available' => $this->wallet->getAvailable()->toString(),
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php bin/phpunit tests/Entity/WalletTest.php tests/Dto/WalletResponseTest.php`
Expected: `OK`.

- [ ] **Step 5: Repository and migration**

`src/Repository/WalletRepository.php` — w `buildEntity()` po linii `balance: …`:

```php
            reserved: Money::of((string) $row['reserved'], $currency),
```

w `insert()` — `values([...])`:

```php
            ->values([
                'user_id' => ':user_id',
                'currency' => ':currency',
                'balance' => ':balance',
                'reserved' => ':reserved',
                'is_blocked' => ':is_blocked',
                'last_activity_at' => ':last_activity_at',
                'created_at' => ':created_at',
            ]);
```

i w parametrach `insert()` po `'balance' => $wallet->getBalance()->toString(),`:

```php
                'reserved' => $wallet->getReserved()->toString(),
```

w `update()`:

```php
        $qb
            ->update(self::TABLE_NAME)
            ->set('balance', ':balance')
            ->set('reserved', ':reserved')
            ->set('is_blocked', ':is_blocked')
            ->set('last_activity_at', ':last_activity_at')
            ->where('id = :id');
```

i w parametrach `update()` po `'balance' => $wallet->getBalance()->toString(),`:

```php
                'reserved' => $wallet->getReserved()->toString(),
```

`migrations/Version20261009130000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009130000 extends AbstractMigration
{
    private const string IN_FLIGHT = "('pending', 'fraud_review')";

    public function getDescription(): string
    {
        return 'Add wallets.reserved and move in-flight transfers to the reservation model';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `wallets` ADD `reserved` DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER `balance`');

        // In-flight transfers were placed under the old model: money already left the source and reached the target.
        // Undo that move and reserve the source amount instead, so complete()/reject() settle them exactly once.
        $this->addSql(sprintf(<<<'SQL'
            UPDATE `wallets` w
            JOIN (
                SELECT `from_wallet_id` AS wallet_id, SUM(`from_amount`) AS amount
                FROM `transactions` WHERE `status` IN %s GROUP BY `from_wallet_id`
            ) t ON t.wallet_id = w.id
            SET w.`balance` = w.`balance` + t.amount, w.`reserved` = w.`reserved` + t.amount
            SQL, self::IN_FLIGHT));
        $this->addSql(sprintf(<<<'SQL'
            UPDATE `wallets` w
            JOIN (
                SELECT `to_wallet_id` AS wallet_id, SUM(`to_amount`) AS amount
                FROM `transactions` WHERE `status` IN %s GROUP BY `to_wallet_id`
            ) t ON t.wallet_id = w.id
            SET w.`balance` = w.`balance` - t.amount
            SQL, self::IN_FLIGHT));
    }

    public function down(Schema $schema): void
    {
        $this->addSql(sprintf(<<<'SQL'
            UPDATE `wallets` w
            JOIN (
                SELECT `from_wallet_id` AS wallet_id, SUM(`from_amount`) AS amount
                FROM `transactions` WHERE `status` IN %s GROUP BY `from_wallet_id`
            ) t ON t.wallet_id = w.id
            SET w.`balance` = w.`balance` - t.amount
            SQL, self::IN_FLIGHT));
        $this->addSql(sprintf(<<<'SQL'
            UPDATE `wallets` w
            JOIN (
                SELECT `to_wallet_id` AS wallet_id, SUM(`to_amount`) AS amount
                FROM `transactions` WHERE `status` IN %s GROUP BY `to_wallet_id`
            ) t ON t.wallet_id = w.id
            SET w.`balance` = w.`balance` + t.amount
            SQL, self::IN_FLIGHT));
        $this->addSql('ALTER TABLE `wallets` DROP `reserved`');
    }
}
```

- [ ] **Step 6: Run migration on dev DB and verify in-flight data**

```bash
docker exec mariadb mariadb -uroot -proot app -e "DROP TABLE IF EXISTS tmp_wallet_snapshot; CREATE TABLE tmp_wallet_snapshot AS SELECT id, balance FROM wallets;"
php bin/console doctrine:migrations:migrate -n
docker exec mariadb mariadb -uroot -proot app -e "
SELECT w.id, s.balance AS before_balance, w.balance, w.reserved,
       COALESCE(o.amount, 0) AS expected_reserved,
       s.balance + COALESCE(o.amount, 0) - COALESCE(i.amount, 0) AS expected_balance
FROM wallets w JOIN tmp_wallet_snapshot s ON s.id = w.id
LEFT JOIN (SELECT from_wallet_id id, SUM(from_amount) amount FROM transactions WHERE status IN ('pending','fraud_review') GROUP BY from_wallet_id) o ON o.id = w.id
LEFT JOIN (SELECT to_wallet_id id, SUM(to_amount) amount FROM transactions WHERE status IN ('pending','fraud_review') GROUP BY to_wallet_id) i ON i.id = w.id
HAVING w.reserved <> expected_reserved OR w.balance <> expected_balance;"
```

Expected: `[OK] Successfully migrated to version: DoctrineMigrations\Version20261009130000`; zapytanie kontrolne zwraca **0 wierszy**.

Round-trip:

```bash
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261009130000' --down -n
docker exec mariadb mariadb -uroot -proot app -e "SELECT w.id FROM wallets w JOIN tmp_wallet_snapshot s ON s.id = w.id WHERE w.balance <> s.balance;"
php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261009130000' --up -n
docker exec mariadb mariadb -uroot -proot app -e "DROP TABLE tmp_wallet_snapshot;"
php bin/console doctrine:migrations:migrate -n --env=test
```

Expected: oba `execute` → `[OK]`; po `--down` zapytanie zwraca **0 wierszy** (salda jak przed migracją); migracja `--env=test` (baza `app_test`) → `[OK]`.

- [ ] **Step 7: Verify live**

```bash
TOKEN=$(php bin/console app:create-user | grep -oE '[a-f0-9]{64}' | tail -1)
curl -sk -X POST -H "Authorization: Bearer $TOKEN" -d '{"currency":"PLN"}' https://127.0.0.1:8000/api/wallets; echo
```

Expected: `{"id":…,"currency":"PLN","balance":"0.00","reserved":"0.00","available":"0.00",…}`.

- [ ] **Step 8: Commit**

```bash
git add src/Exception/InsufficientFundsException.php src/Entity/Wallet.php src/Dto/WalletResponse.php src/Repository/WalletRepository.php migrations/Version20261009130000.php tests/Support/WalletFixture.php tests/Entity/WalletTest.php tests/Dto/WalletResponseTest.php
git commit -m "feat: add fund reservation to wallets

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Transakcje DB, blokady wierszy, jednokrotne rozliczenie

**Files:**
- Create: `src/Repository/TransactionManagerInterface.php`, `src/Repository/DbalTransactionManager.php`, `src/Exception/TransactionAlreadyProcessedException.php`, `docker/mariadb/init/01-test-database.sql`, `tests/Support/ImmediateTransactionManager.php`, `tests/Integration/DatabaseTestCase.php`, `tests/Integration/Repository/DbalTransactionManagerTest.php`, `tests/Integration/Repository/TransactionRepositoryTest.php`, `tests/Integration/Repository/WalletRepositoryReservedTest.php`, `tests/Repository/WalletRepositoryLockTest.php`
- Modify: `src/Repository/WalletRepositoryInterface.php`, `src/Repository/WalletRepository.php`, `src/Repository/TransactionRepository.php`, `docker-compose.yml`, `README.md`

**Interfaces:**
- Produces:
  - `TransactionManagerInterface::transactional(callable $operation): mixed` — wynik `$operation()`; wyjątek → rollback i rethrow.
  - `WalletRepositoryInterface::lockForUpdate(int ...$ids): void` — `SELECT … FOR UPDATE`, ids unikalne, rosnąco.
  - `TransactionRepository::save()` dla istniejącej transakcji rzuca `TransactionAlreadyProcessedException(int $transactionId)` (`Transaction {id} was already processed.`), gdy w bazie nie jest już `pending`/`fraud_review`.
  - `App\Tests\Support\ImmediateTransactionManager` (publiczne `int $calls`), `App\Tests\Integration\DatabaseTestCase` (`createUser(): User`, `createWallet(User, Currency, string $balance = '0'): Wallet`, `service(string $id): object`).

- [ ] **Step 1: Test DB access for the `app` user**

`docker/mariadb/init/01-test-database.sql`:

```sql
-- Runs only when the MariaDB volume is created for the first time.
CREATE DATABASE IF NOT EXISTS `app_test`;
GRANT ALL PRIVILEGES ON `app_test`.* TO 'app'@'%';
```

`docker-compose.yml` — w usłudze `mariadb` po `ports:` dodaj:

```yaml
    volumes:
      - ./docker/mariadb/init:/docker-entrypoint-initdb.d:ro
```

Na istniejącym wolumenie skrypt się nie wykona — zastosuj go ręcznie (idempotentny):

Run: `docker exec -i mariadb mariadb -uroot -proot < docker/mariadb/init/01-test-database.sql && docker exec mariadb mariadb -uapp -papp app_test -e "SELECT 1"`
Expected: `1`.

`README.md` — w sekcji `## Running commands inside the PHP container` po przykładzie z testami dodaj:

~~~markdown
Integration tests use the `app_test` database (created by `docker/mariadb/init/01-test-database.sql` on the first
start of the MariaDB volume). Apply migrations to it once before running tests:

```bash
php bin/console doctrine:migrations:migrate -n --env=test
```
~~~

- [ ] **Step 2: Write the failing tests**

`tests/Support/ImmediateTransactionManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Repository\TransactionManagerInterface;

/**
 * Runs the operation directly; counts calls so unit tests can assert a single transaction boundary.
 */
final class ImmediateTransactionManager implements TransactionManagerInterface
{
    public int $calls = 0;

    public function transactional(callable $operation): mixed
    {
        ++$this->calls;

        return $operation();
    }
}
```

`tests/Integration/DatabaseTestCase.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\User;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Repository\UserRepository;
use App\Repository\WalletRepository;
use App\ValueObject\Money;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Runs each test inside a database transaction that is rolled back afterwards (database: app_test).
 * Services' own transactions nest as savepoints.
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        return self::getContainer()->get($id);
    }

    protected function createUser(): User
    {
        $user = new User(
            id: null,
            email: bin2hex(random_bytes(8)).'@example.com',
            roles: ['ROLE_USER'],
            createdAt: new DateTimeImmutable(),
        );
        $this->service(UserRepository::class)->save($user);

        return $user;
    }

    protected function createWallet(User $user, Currency $currency, string $balance = '0'): Wallet
    {
        $wallet = Wallet::create($user->getIdNotNull(), $currency);
        $amount = Money::of($balance, $currency);
        if ($amount->isGreaterThan(Money::zero($currency))) {
            $wallet->credit($amount);
        }
        $this->service(WalletRepository::class)->save($wallet);

        return $wallet;
    }
}
```

`tests/Integration/Repository/DbalTransactionManagerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Enum\Currency;
use App\Repository\DbalTransactionManager;
use App\Repository\WalletRepository;
use App\Tests\Integration\DatabaseTestCase;
use RuntimeException;

class DbalTransactionManagerTest extends DatabaseTestCase
{
    public function testReturnsOperationResult(): void
    {
        $result = $this->service(DbalTransactionManager::class)->transactional(static fn (): string => 'done');

        self::assertSame('done', $result);
    }

    public function testRollsBackOnException(): void
    {
        $user = $this->createUser();
        $wallets = $this->service(WalletRepository::class);

        try {
            $this->service(DbalTransactionManager::class)->transactional(function () use ($user): void {
                $this->createWallet($user, Currency::PLN, '10.00');
                throw new RuntimeException('boom');
            });
            self::fail('Expected exception.');
        } catch (RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertNull($wallets->findByUserIdAndCurrency($user->getIdNotNull(), Currency::PLN));
    }
}
```

`tests/Integration/Repository/WalletRepositoryReservedTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Enum\Currency;
use App\Repository\WalletRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\ValueObject\Money;

class WalletRepositoryReservedTest extends DatabaseTestCase
{
    public function testPersistsReservedAmount(): void
    {
        $wallet = $this->createWallet($this->createUser(), Currency::JPY, '1000');
        $wallet->reserve(Money::of('250', Currency::JPY));

        $repository = $this->service(WalletRepository::class);
        $repository->save($wallet);
        $reloaded = $repository->findById((int) $wallet->getId());

        self::assertSame('1000', $reloaded?->getBalance()->toString());
        self::assertSame('250', $reloaded?->getReserved()->toString());
        self::assertSame('750', $reloaded?->getAvailable()->toString());
    }

    public function testLockForUpdateAcceptsDuplicatesAndUnknownIds(): void
    {
        $wallet = $this->createWallet($this->createUser(), Currency::PLN);

        $this->service(WalletRepository::class)->lockForUpdate((int) $wallet->getId(), 999999999, (int) $wallet->getId());

        $this->addToAssertionCount(1);
    }
}
```

`tests/Integration/Repository/TransactionRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\TransactionAlreadyProcessedException;
use App\Repository\TransactionRepository;
use App\Tests\Integration\DatabaseTestCase;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;

class TransactionRepositoryTest extends DatabaseTestCase
{
    public function testSecondStatusChangeFromStaleCopyIsRefused(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $id = $this->createPendingTransaction();

        $first = $repository->findById($id);
        $stale = $repository->findById($id);

        $first?->setStatus(TransactionStatus::COMPLETED);
        $repository->save($first);

        $stale?->setStatus(TransactionStatus::REJECTED);

        try {
            $repository->save($stale);
            self::fail('Expected TransactionAlreadyProcessedException.');
        } catch (TransactionAlreadyProcessedException $e) {
            self::assertSame(sprintf('Transaction %d was already processed.', $id), $e->getMessage());
        }

        self::assertSame(TransactionStatus::COMPLETED, $repository->findById($id)?->getStatus());
    }

    public function testStatusChangeFromFraudReviewIsAllowed(): void
    {
        $repository = $this->service(TransactionRepository::class);
        $id = $this->createPendingTransaction(requiresAntiFraudCheck: true);

        $transaction = $repository->findById($id);
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction?->getStatus());

        $transaction->setStatus(TransactionStatus::REJECTED);
        $repository->save($transaction);

        self::assertSame(TransactionStatus::REJECTED, $repository->findById($id)?->getStatus());
    }

    private function createPendingTransaction(bool $requiresAntiFraudCheck = false): int
    {
        $user = $this->createUser();
        $from = $this->createWallet($user, Currency::PLN, '100.00');
        $to = $this->createWallet($user, Currency::EUR);

        $transaction = Transaction::create(
            fromWalletId: (int) $from->getId(),
            toWalletId: (int) $to->getId(),
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('23.43', Currency::EUR),
            spread: Money::of('0.16', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.235910'),
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
        $this->service(TransactionRepository::class)->save($transaction);

        return (int) $transaction->getId();
    }
}
```

`tests/Repository/WalletRepositoryLockTest.php` (Review Focus #1):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\WalletRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\TestCase;

class WalletRepositoryLockTest extends TestCase
{
    public function testLocksDistinctIdsInAscendingOrder(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('executeQuery')
            ->with(
                'SELECT id FROM wallets WHERE id IN (?) ORDER BY id FOR UPDATE',
                [[3, 9]],
                [ArrayParameterType::INTEGER],
            )
            ->willReturn($this->createStub(Result::class));

        new WalletRepository($connection)->lockForUpdate(9, 3, 9);
    }

    public function testDoesNothingWithoutIds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeQuery');

        new WalletRepository($connection)->lockForUpdate();
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php bin/phpunit tests/Integration/Repository tests/Repository`
Expected: Errors — `Class "App\Repository\DbalTransactionManager" not found`, `Call to undefined method App\Repository\WalletRepository::lockForUpdate()`; `testSecondStatusChangeFromStaleCopyIsRefused` FAIL (`Expected TransactionAlreadyProcessedException.`).

- [ ] **Step 4: Implement**

`src/Repository/TransactionManagerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

interface TransactionManagerInterface
{
    /**
     * Runs the operation in a database transaction: commits on success, rolls back and rethrows on exception.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function transactional(callable $operation): mixed;
}
```

`src/Repository/DbalTransactionManager.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use Doctrine\DBAL\Connection;

readonly class DbalTransactionManager implements TransactionManagerInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $operation): mixed
    {
        return $this->connection->transactional($operation(...));
    }
}
```

`src/Exception/TransactionAlreadyProcessedException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class TransactionAlreadyProcessedException extends RuntimeException
{
    public function __construct(int $transactionId)
    {
        parent::__construct(sprintf('Transaction %d was already processed.', $transactionId));
    }
}
```

`src/Repository/WalletRepositoryInterface.php` — dodaj metodę:

```php
    /**
     * Locks the wallets' rows until the current database transaction ends.
     * Ids are deduplicated and locked in ascending order, so concurrent callers cannot deadlock each other.
     */
    public function lockForUpdate(int ...$ids): void;
```

`src/Repository/WalletRepository.php` — import `use Doctrine\DBAL\ArrayParameterType;`, metoda po `save()`:

```php
    /**
     * @throws Exception
     */
    public function lockForUpdate(int ...$ids): void
    {
        if ([] === $ids) {
            return;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        $this->connection->executeQuery(
            sprintf('SELECT id FROM %s WHERE id IN (?) ORDER BY id FOR UPDATE', self::TABLE_NAME),
            [$ids],
            [ArrayParameterType::INTEGER],
        );
    }
```

`src/Repository/TransactionRepository.php` — import `use App\Exception\TransactionAlreadyProcessedException;`; `update()`:

```php
    /**
     * Only an in-flight transaction (pending or fraud review) can change its status, so a transaction is settled once.
     *
     * @throws Exception
     * @throws TransactionAlreadyProcessedException
     */
    private function update(Transaction $transaction): void
    {
        $qb = $this->connection->createQueryBuilder();

        $qb
            ->update(self::TABLE_NAME)
            ->set('status', ':status')
            ->set('anti_fraud_checked_at', ':anti_fraud_checked_at')
            ->where('id = :id')
            ->andWhere('status IN (:pending, :fraud_review)');

        $affectedRows = $this->connection->executeStatement(
            $qb->getSQL(),
            [
                'status' => $transaction->getStatus()->value,
                'anti_fraud_checked_at' => $transaction->getAntiFraudCheckedAt()
                    ?->setTimezone(timezone: new DateTimeZone('UTC'))
                    ->format('Y-m-d H:i:s'),
                'id' => $transaction->getId(),
                'pending' => TransactionStatus::PENDING->value,
                'fraud_review' => TransactionStatus::FRAUD_REVIEW->value,
            ]
        );

        if (0 === $affectedRows) {
            throw new TransactionAlreadyProcessedException((int) $transaction->getId());
        }
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php bin/phpunit tests/Integration/Repository tests/Repository`
Expected: `OK` (7 testów).

- [ ] **Step 6: Commit**

```bash
git add src/Repository/TransactionManagerInterface.php src/Repository/DbalTransactionManager.php src/Exception/TransactionAlreadyProcessedException.php src/Repository/WalletRepositoryInterface.php src/Repository/WalletRepository.php src/Repository/TransactionRepository.php docker/mariadb/init/01-test-database.sql docker-compose.yml README.md tests/Support/ImmediateTransactionManager.php tests/Integration tests/Repository
git commit -m "feat: add database transactions, wallet row locks and settle-once guard

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Wpłata — limit per waluta, transakcja DB, `credit()`

**Files:**
- Create: `src/Exception/DepositLimitExceededException.php`, `tests/Integration/TransactionLimitsWiringTest.php`
- Modify: `src/Service/DepositService.php`, `src/Controller/WalletController.php`
- Test: `tests/Service/DepositServiceTest.php` (pełne przepisanie), `tests/Controller/WalletControllerTest.php`

**Interfaces:**
- Consumes: `TransactionLimits::depositLimit()`, `TransactionManagerInterface`, `WalletRepositoryInterface::lockForUpdate()`, `Wallet::credit()`, `WalletFixture`, `ImmediateTransactionManager`, `TransactionLimitsFactory`.
- Produces:
  - `new DepositService(WalletRepositoryInterface, TransactionLimits, TransactionManagerInterface)`; `DepositService::MAX_AMOUNT` **usunięte**.
  - `DepositLimitExceededException(Money $limit)` — `Amount cannot exceed {limit} {CUR}.`

- [ ] **Step 1: Write the failing tests**

`tests/Service/DepositServiceTest.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Exception\DepositLimitExceededException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use App\Service\DepositService;
use App\Tests\Support\ImmediateTransactionManager;
use App\Tests\Support\TransactionLimitsFactory;
use App\Tests\Support\WalletFixture;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class DepositServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private ImmediateTransactionManager $transactionManager;
    private DepositService $depositService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionManager = new ImmediateTransactionManager();
        $this->depositService = new DepositService(
            $this->walletRepository,
            TransactionLimitsFactory::default(),
            $this->transactionManager,
        );
    }

    public function testDepositSuccessfully(): void
    {
        $wallet = WalletFixture::create(1, 1, Currency::PLN);

        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(1);
        $this->walletRepository
            ->expects(self::once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);
        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::identicalTo($wallet));

        $result = $this->depositService->deposit(1, 1, '500.00');

        self::assertSame('500.00', $result->getBalance()->toString());
        self::assertNotNull($result->getLastActivityAt());
        self::assertSame(1, $this->transactionManager->calls);
    }

    public function testDepositAddsToExistingBalance(): void
    {
        $wallet = WalletFixture::create(1, 1, Currency::EUR, '200.00');
        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->depositService->deposit(1, 1, '300.00');

        self::assertSame('500.00', $wallet->getBalance()->toString());
    }

    public function testDepositIsExactForDecimalFractions(): void
    {
        $wallet = WalletFixture::create(1, 1, Currency::PLN, '0.10');
        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->depositService->deposit(1, 1, '0.20');

        self::assertSame('0.30', $wallet->getBalance()->toString());
    }

    #[DataProvider('limitProvider')]
    public function testDepositAcceptsExactlyTheCurrencyLimit(Currency $currency, string $limit): void
    {
        $wallet = WalletFixture::create(1, 1, $currency);
        $this->walletRepository->method('findById')->willReturn($wallet);

        $this->depositService->deposit(1, 1, $limit);

        self::assertSame($limit, $wallet->getBalance()->toString());
    }

    public static function limitProvider(): Generator
    {
        yield 'PLN' => [Currency::PLN, '10000.00'];
        yield 'EUR' => [Currency::EUR, '2500.00'];
        yield 'JPY' => [Currency::JPY, '450000'];
    }

    #[DataProvider('overLimitProvider')]
    public function testDepositThrowsWhenAmountExceedsCurrencyLimit(Currency $currency, string $amount, string $message): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 1, $currency));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(DepositLimitExceededException::class);
        $this->expectExceptionMessage($message);

        $this->depositService->deposit(1, 1, $amount);
    }

    public static function overLimitProvider(): Generator
    {
        yield 'PLN' => [Currency::PLN, '10000.01', 'Amount cannot exceed 10000.00 PLN.'];
        yield 'EUR' => [Currency::EUR, '2500.01', 'Amount cannot exceed 2500.00 EUR.'];
        yield 'JPY' => [Currency::JPY, '450001', 'Amount cannot exceed 450000 JPY.'];
    }

    public function testDepositThrowsWhenAmountHasTooManyDecimalPlaces(): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 1, Currency::JPY));
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
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 2, Currency::PLN));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->depositService->deposit(1, 1, '100.00');
    }

    public function testDepositThrowsWhenWalletIsBlocked(): void
    {
        $this->walletRepository->method('findById')->willReturn(WalletFixture::create(1, 1, Currency::PLN, blocked: true));
        $this->walletRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 1 is blocked.');

        $this->depositService->deposit(1, 1, '100.00');
    }
}
```

`tests/Integration/TransactionLimitsWiringTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Enum\Currency;
use App\Service\TransactionLimits;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class TransactionLimitsWiringTest extends KernelTestCase
{
    public function testLimitsComeFromServiceParameters(): void
    {
        self::bootKernel();
        $limits = self::getContainer()->get(TransactionLimits::class);

        self::assertSame('2500.00', $limits->depositLimit(Currency::EUR)->toString());
        self::assertSame('10000.00', $limits->depositLimit(Currency::PLN)->toString());
        self::assertSame('650000', $limits->antiFraudThreshold(Currency::JPY)->toString());
        self::assertSame('1250000.00', $limits->antiFraudThreshold(Currency::HUF)->toString());
    }
}
```

`tests/Controller/WalletControllerTest.php`:
- dodaj importy `use App\Exception\DepositLimitExceededException;`;
- **usuń** testy `testDepositReturnsBadRequestWhenAmountExceedsMax`, `testDepositAcceptsExactlyMaxAmount`, `testDepositReturnsBadRequestWhenAmountJustAboveMax` (granice limitu testuje teraz `DepositServiceTest`);
- dopisz:

```php
    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountExceedsCurrencyLimit(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->method('deposit')
            ->willThrowException(new DepositLimitExceededException(Money::of('2500', Currency::EUR)));

        $request = new Request(content: json_encode(['amount' => '2500.01'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount cannot exceed 2500.00 EUR.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositPassesLargeAmountToService(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->expects(self::once())
            ->method('deposit')
            ->with(1, 5, '450000')
            ->willReturn(Wallet::create(1, Currency::JPY));

        $request = new Request(content: json_encode(['amount' => '450000'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(200, $response->getStatusCode());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Service/DepositServiceTest.php tests/Controller/WalletControllerTest.php tests/Integration/TransactionLimitsWiringTest.php`
Expected: Errors — `Class "App\Exception\DepositLimitExceededException" not found`, `DepositService::__construct()` argument errors; `testDepositPassesLargeAmountToService` FAIL (400 z kontrolera), wiring test Error (serwis usunięty z kontenera, bo nikt go nie używa).

- [ ] **Step 3: Implement**

`src/Exception/DepositLimitExceededException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use App\ValueObject\Money;
use RuntimeException;

final class DepositLimitExceededException extends RuntimeException
{
    public function __construct(Money $limit)
    {
        parent::__construct(sprintf('Amount cannot exceed %s %s.', $limit->toString(), $limit->getCurrency()->value));
    }
}
```

`src/Service/DepositService.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Exception\DepositLimitExceededException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionManagerInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use DateTimeImmutable;

readonly class DepositService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionLimits $transactionLimits,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    /**
     * @throws WalletNotFoundException
     * @throws InvalidMoneyAmountException
     * @throws DepositLimitExceededException
     * @throws WalletBlockedException
     */
    public function deposit(int $userId, int $walletId, string $amount): Wallet
    {
        return $this->transactionManager->transactional(function () use ($userId, $walletId, $amount): Wallet {
            $this->walletRepository->lockForUpdate($walletId);

            $wallet = $this->walletRepository->findById($walletId);
            if (null === $wallet || $wallet->getUserId() !== $userId) {
                throw new WalletNotFoundException($walletId);
            }

            $money = Money::of($amount, $wallet->getCurrency());

            $limit = $this->transactionLimits->depositLimit($wallet->getCurrency());
            if ($money->isGreaterThan($limit)) {
                throw new DepositLimitExceededException($limit);
            }

            if ($wallet->isBlocked()) {
                throw new WalletBlockedException($walletId);
            }

            $wallet->credit($money);
            $wallet->setLastActivityAt(new DateTimeImmutable());
            $this->walletRepository->save($wallet);

            return $wallet;
        });
    }
}
```

`src/Controller/WalletController.php`:
- import `use App\Exception\DepositLimitExceededException;`;
- w `deposit()` **usuń** blok:

```php
        if (new Number($amount)->compare(DepositService::MAX_AMOUNT) > 0) {
            return new JsonResponse(['error' => sprintf('Amount cannot exceed %s.', DepositService::MAX_AMOUNT)], Response::HTTP_BAD_REQUEST);
        }
```

- w `deposit()` w `try` dodaj po `catch (InvalidMoneyAmountException $e)`:

```php
        } catch (DepositLimitExceededException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service/DepositServiceTest.php tests/Controller/WalletControllerTest.php tests/Integration/TransactionLimitsWiringTest.php && php bin/console lint:container`
Expected: `OK`; `[OK] The container was linted successfully`.

- [ ] **Step 5: Verify live**

```bash
TOKEN=$(php bin/console app:create-user | grep -oE '[a-f0-9]{64}' | tail -1); A="Authorization: Bearer $TOKEN"; U=https://127.0.0.1:8000/api/wallets
E=$(curl -sk -X POST -H "$A" -d '{"currency":"EUR"}' $U | grep -oE '"id":[0-9]+' | cut -d: -f2)
curl -sk -X POST -H "$A" -d '{"amount":"2500.01"}' $U/$E/deposit; echo
curl -sk -X POST -H "$A" -d '{"amount":"2500"}' $U/$E/deposit; echo
```

Expected: `{"error":"Amount cannot exceed 2500.00 EUR."}`, potem `"balance":"2500.00"`.

- [ ] **Step 6: Commit**

```bash
git add src/Exception/DepositLimitExceededException.php src/Service/DepositService.php src/Controller/WalletController.php tests/Service/DepositServiceTest.php tests/Controller/WalletControllerTest.php tests/Integration/TransactionLimitsWiringTest.php
git commit -m "feat: per-currency deposit limit inside a locked database transaction

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Przelew rezerwuje środki

**Files:**
- Create: `src/Exception/SameWalletTransferException.php`
- Modify: `src/Service/TransferService.php`, `src/Controller/WalletController.php`
- Test: `tests/Service/TransferServiceTest.php` (pełne przepisanie), `tests/Controller/WalletControllerTest.php`

**Interfaces:**
- Consumes: `Wallet::reserve()`, `InsufficientFundsException`, `WalletBlockedException`, `TransactionLimits::antiFraudThreshold()`, `TransactionManagerInterface`, `lockForUpdate()`, fixtures z Tasków 1–3.
- Produces:
  - `new TransferService(WalletRepositoryInterface, TransactionRepositoryInterface, ExchangeRateService, SpreadService, TransactionLimits, TransactionManagerInterface)`; `TransferService::ANTI_FRAUD_THRESHOLD` **usunięte**.
  - `SameWalletTransferException()` — `Cannot transfer to the same wallet.`
  - HTTP: same wallet → 400; blocked / insufficient → 422.

- [ ] **Step 1: Write the failing tests**

`tests/Service/TransferServiceTest.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
use App\Tests\Support\ImmediateTransactionManager;
use App\Tests\Support\TransactionLimitsFactory;
use App\Tests\Support\WalletFixture;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use Generator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransferServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private ExchangeRateService $exchangeRateService;
    private SpreadService $spreadService;
    private ImmediateTransactionManager $transactionManager;
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);
        $this->transactionManager = new ImmediateTransactionManager();

        $this->transferService = $this->makeService($this->exchangeRateService, $this->spreadService);
    }

    /**
     * Intent kept from the original test: placing a transfer does not change any balance — it only reserves funds.
     */
    public function testTransferSuccessfully(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet->method('getCurrency')->willReturn(Currency::PLN);
        $fromWallet->method('getUserId')->willReturn($userId);
        $fromWallet
            ->expects($this->once())
            ->method('reserve')
            ->with($this->callback(static fn (Money $amount): bool => Currency::PLN === $amount->getCurrency() && '1000.00' === $amount->toString()));
        $fromWallet->expects($this->never())->method('settle');
        $fromWallet->expects($this->never())->method('credit');
        $toWallet = $this->createMock(Wallet::class);
        $toWallet->method('getCurrency')->willReturn(Currency::EUR);
        $toWallet->method('getUserId')->willReturn($userId);
        $toWallet->expects($this->never())->method('credit');
        $toWallet->expects($this->never())->method('reserve');

        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(1, 2);
        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->exchangeRateService
            ->expects(self::once())
            ->method('getExchangeRateBetween')
            ->with(Currency::PLN, Currency::EUR)
            ->willReturn(ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'));

        $this->spreadService
            ->expects(self::once())
            ->method('calculateSpread')
            ->with(
                $this->callback(static fn (Money $price): bool => Currency::EUR === $price->getCurrency() && '250.00' === $price->toString()),
                Currency::PLN,
                Currency::EUR,
            )
            ->willReturn(Money::of('1.00', Currency::EUR));

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::identicalTo($fromWallet));

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.00', $transaction->getFromAmount()->toString());
        self::assertSame('249.00', $transaction->getToAmount()->toString());
        self::assertSame('0.250000', $transaction->getExchangeRate()->toString());
        self::assertSame('1.00', $transaction->getSpread()->toString());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
        self::assertSame(1, $this->transactionManager->calls);
    }

    public function testTransferLocksWalletsBeforeReadingThem(): void
    {
        $calls = [];
        $wallets = [1 => WalletFixture::create(1, 1, Currency::PLN, '100.00'), 2 => WalletFixture::create(2, 1, Currency::EUR)];

        $this->walletRepository
            ->method('lockForUpdate')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'lock';
            });
        $this->walletRepository
            ->method('findById')
            ->willReturnCallback(static function (int $id) use (&$calls, $wallets): ?Wallet {
                $calls[] = 'find';

                return $wallets[$id] ?? null;
            });

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '10.00');

        self::assertSame(['lock', 'find', 'find'], $calls);
    }

    #[DataProvider('referenceTransferProvider')]
    public function testTransferReservesFundsAndCalculatesAmounts(
        Currency $toCurrency,
        string $expectedRate,
        string $expectedSpread,
        string $expectedToAmount,
    ): void {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00');
        $toWallet = WalletFixture::create(2, 1, $toCurrency);
        $this->givenWallets($fromWallet, $toWallet);

        $transaction = $this->makeServiceWithRealRates()->transfer(1, 1, 2, '100.00');

        self::assertSame('100.00', $transaction->getFromAmount()->toString());
        self::assertSame($expectedRate, $transaction->getExchangeRate()->toString());
        self::assertSame($expectedSpread, $transaction->getSpread()->toString());
        self::assertSame($expectedToAmount, $transaction->getToAmount()->toString());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
        self::assertSame('100.00', $fromWallet->getReserved()->toString());
        self::assertSame('400.00', $fromWallet->getAvailable()->toString());
        self::assertSame(Money::zero($toCurrency)->toString(), $toWallet->getBalance()->toString());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
    }

    public static function referenceTransferProvider(): Generator
    {
        // 100 PLN × rate → gross (rounded to target scale) − spread = net
        yield 'PLN to HUF' => [Currency::HUF, '84.745763', '89.21', '8385.37'];
        yield 'PLN to JPY' => [Currency::JPY, '43.668122', '34', '4333'];
        yield 'PLN to EUR' => [Currency::EUR, '0.235910', '0.16', '23.43'];
    }

    #[DataProvider('antiFraudProvider')]
    public function testAntiFraudThresholdUsesSourceCurrency(Currency $from, Currency $to, string $amount, bool $expectedReview): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, $from, '2000000'), WalletFixture::create(2, 1, $to));

        $transaction = $this->makeServiceWithRealRates()->transfer(1, 1, 2, $amount);

        self::assertSame($expectedReview, $transaction->requiresAntiFraudCheck());
        self::assertSame(
            $expectedReview ? TransactionStatus::FRAUD_REVIEW : TransactionStatus::PENDING,
            $transaction->getStatus(),
        );
    }

    public static function antiFraudProvider(): Generator
    {
        yield 'PLN exactly at threshold' => [Currency::PLN, Currency::EUR, '15000.00', false];
        yield 'PLN just above threshold' => [Currency::PLN, Currency::EUR, '15000.01', true];
        yield 'USD just above its own threshold' => [Currency::USD, Currency::PLN, '4000.01', true];
        yield 'JPY just above its own threshold' => [Currency::JPY, Currency::PLN, '650001', true];
        // Old rule compared the target amount (16770.74 HUF > 15000) — the source amount 200 PLN is now below the PLN threshold.
        yield 'PLN to HUF large target amount' => [Currency::PLN, Currency::HUF, '200.00', false];
    }

    public function testTransferOfExactlyAvailableAmountSucceeds(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '100.00', '30.00');
        $this->givenWallets($fromWallet, WalletFixture::create(2, 1, Currency::EUR));

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '70.00');

        self::assertSame('100.00', $fromWallet->getReserved()->toString());
        self::assertSame('0.00', $fromWallet->getAvailable()->toString());
    }

    public function testTransferThrowsWhenFundsAreInsufficient(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00', '30.00'), WalletFixture::create(2, 1, Currency::EUR));
        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InsufficientFundsException::class);
        $this->expectExceptionMessage('Insufficient funds in wallet 1.');

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '70.01');
    }

    public function testTransferFailsFromLegacyNegativeBalance(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '-995.00'), WalletFixture::create(2, 1, Currency::EUR));

        $this->expectException(InsufficientFundsException::class);

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '1.00');
    }

    public function testTransferThrowsForSameWallet(): void
    {
        $this->walletRepository->expects(self::never())->method('findById');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(SameWalletTransferException::class);
        $this->expectExceptionMessage('Cannot transfer to the same wallet.');

        $this->transferService->transfer(1, 1, 1, '10.00');
    }

    public function testTransferThrowsWhenSourceWalletIsBlocked(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00', blocked: true), WalletFixture::create(2, 1, Currency::EUR));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletBlockedException::class);
        $this->expectExceptionMessage('Wallet 1 is blocked.');

        $this->makeServiceWithRealRates()->transfer(1, 1, 2, '10.00');
    }

    public function testTransferThrowsWhenTargetWalletIsBlocked(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '100.00');
        $this->givenWallets($fromWallet, WalletFixture::create(2, 1, Currency::EUR, blocked: true));
        $this->transactionRepository->expects(self::never())->method('save');

        try {
            $this->makeServiceWithRealRates()->transfer(1, 1, 2, '10.00');
            self::fail('Expected WalletBlockedException.');
        } catch (WalletBlockedException $e) {
            self::assertSame('Wallet 2 is blocked.', $e->getMessage());
        }

        self::assertSame('0.00', $fromWallet->getReserved()->toString());
    }

    public function testTransferThrowsWhenAmountHasTooManyDecimalPlaces(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00'), WalletFixture::create(2, 1, Currency::EUR));
        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for PLN.');

        $this->transferService->transfer(1, 1, 2, '10.001');
    }

    public function testTransferThrowsWhenAmountHasDecimalsForJpyWallet(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::JPY, '1000'), WalletFixture::create(2, 1, Currency::PLN));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for JPY.');

        $this->transferService->transfer(1, 1, 2, '100.5');
    }

    public function testTransferThrowsWhenFromWalletNotFound(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(99)
            ->willReturn(null);
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 99, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletNotFound(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, WalletFixture::create(1, 1, Currency::PLN, '100.00')],
                [99, null],
            ]);
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 1, 99, '100.00');
    }

    public function testTransferThrowsWhenFromWalletBelongsToOtherUser(): void
    {
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(WalletFixture::create(1, 2, Currency::PLN, '100.00'));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletBelongsToOtherUser(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '100.00'), WalletFixture::create(2, 2, Currency::EUR));
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    private function givenWallets(Wallet $fromWallet, Wallet $toWallet): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [(int) $fromWallet->getId(), $fromWallet],
                [(int) $toWallet->getId(), $toWallet],
            ]);
    }

    private function makeServiceWithRealRates(): TransferService
    {
        return $this->makeService(new ExchangeRateService(), new SpreadService());
    }

    private function makeService(ExchangeRateService $exchangeRateService, SpreadService $spreadService): TransferService
    {
        return new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $exchangeRateService,
            $spreadService,
            TransactionLimitsFactory::default(),
            $this->transactionManager,
        );
    }
}
```

`tests/Controller/WalletControllerTest.php` — importy `use App\Exception\InsufficientFundsException;`, `use App\Exception\SameWalletTransferException;`; dopisz:

```php
    /**
     * @throws Throwable
     */
    #[DataProvider('transferErrorProvider')]
    public function testTransferMapsDomainErrors(Throwable $exception, int $expectedStatus, string $expectedMessage): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService->method('transfer')->willThrowException($exception);

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '10.00',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame($expectedStatus, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expectedMessage, $data['error']);
    }

    public static function transferErrorProvider(): Generator
    {
        yield 'same wallet' => [new SameWalletTransferException(), 400, 'Cannot transfer to the same wallet.'];
        yield 'insufficient funds' => [new InsufficientFundsException(1), 422, 'Insufficient funds in wallet 1.'];
        yield 'blocked wallet' => [new WalletBlockedException(2), 422, 'Wallet 2 is blocked.'];
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Service/TransferServiceTest.php tests/Controller/WalletControllerTest.php`
Expected: Errors — `Class "App\Exception\SameWalletTransferException" not found`, za dużo argumentów konstruktora `TransferService`.

- [ ] **Step 3: Implement**

`src/Exception/SameWalletTransferException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class SameWalletTransferException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot transfer to the same wallet.');
    }
}
```

`src/Service/TransferService.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Exception\InsufficientFundsException;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\SameWalletTransferException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use RoundingMode;

readonly class TransferService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
        private TransactionLimits $transactionLimits,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    /**
     * Places a transfer: reserves the amount on the source wallet; balances change only when it is completed.
     *
     * @throws SameWalletTransferException
     * @throws WalletNotFoundException
     * @throws InvalidMoneyAmountException
     * @throws WalletBlockedException
     * @throws InsufficientFundsException
     */
    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
        if ($fromWalletId === $toWalletId) {
            throw new SameWalletTransferException();
        }

        return $this->transactionManager->transactional(
            fn (): Transaction => $this->placeTransfer($userId, $fromWalletId, $toWalletId, $fromAmount),
        );
    }

    private function placeTransfer(int $userId, int $fromWalletId, int $toWalletId, string $fromAmount): Transaction
    {
        $this->walletRepository->lockForUpdate($fromWalletId, $toWalletId);

        $fromWallet = $this->walletRepository->findById($fromWalletId);
        if (null === $fromWallet || $fromWallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($fromWalletId);
        }

        $toWallet = $this->walletRepository->findById($toWalletId);
        if (null === $toWallet || $toWallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($toWalletId);
        }

        $fromCurrency = $fromWallet->getCurrency();
        $toCurrency = $toWallet->getCurrency();

        $fromMoney = Money::of($fromAmount, $fromCurrency);

        if ($toWallet->isBlocked()) {
            throw new WalletBlockedException($toWalletId);
        }

        if ($fromWallet->isBlocked()) {
            throw new WalletBlockedException($fromWalletId);
        }

        $fromWallet->reserve($fromMoney);

        $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
        $grossToAmount = $fromMoney->convertTo($toCurrency, $exchangeRate, RoundingMode::HalfAwayFromZero);
        $spread = $this->spreadService->calculateSpread($grossToAmount, $fromCurrency, $toCurrency);
        $toAmount = $grossToAmount->subtract($spread);

        $this->walletRepository->save($fromWallet);

        $transaction = Transaction::create(
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromMoney,
            toAmount: $toAmount,
            spread: $spread,
            exchangeRate: $exchangeRate,
            requiresAntiFraudCheck: $fromMoney->isGreaterThan($this->transactionLimits->antiFraudThreshold($fromCurrency)),
        );

        $this->transactionRepository->save($transaction);

        return $transaction;
    }
}
```

`src/Controller/WalletController.php` — importy `use App\Exception\InsufficientFundsException;`, `use App\Exception\SameWalletTransferException;`; w `transfer()` dodaj po `catch (InvalidMoneyAmountException $e)`:

```php
        } catch (SameWalletTransferException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (WalletBlockedException|InsufficientFundsException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service/TransferServiceTest.php tests/Controller/WalletControllerTest.php && php bin/console lint:container`
Expected: `OK` — w tym `testTransferSuccessfully` (wcześniej failujący); container OK.

- [ ] **Step 5: Verify live**

```bash
TOKEN=$(php bin/console app:create-user | grep -oE '[a-f0-9]{64}' | tail -1); A="Authorization: Bearer $TOKEN"; U=https://127.0.0.1:8000/api/wallets
P=$(curl -sk -X POST -H "$A" -d '{"currency":"PLN"}' $U | grep -oE '"id":[0-9]+' | cut -d: -f2)
E=$(curl -sk -X POST -H "$A" -d '{"currency":"EUR"}' $U | grep -oE '"id":[0-9]+' | cut -d: -f2)
curl -sk -X POST -H "$A" -d '{"amount":"500.00"}' $U/$P/deposit; echo
curl -sk -X POST -H "$A" -d "{\"fromWalletId\":$P,\"toWalletId\":$E,\"amount\":\"1000\"}" $U/transfer; echo
curl -sk -X POST -H "$A" -d "{\"fromWalletId\":$P,\"toWalletId\":$P,\"amount\":\"5\"}" $U/transfer; echo
curl -sk -X POST -H "$A" -d "{\"fromWalletId\":$P,\"toWalletId\":$E,\"amount\":\"100.00\"}" $U/transfer; echo
curl -sk -H "$A" $U; echo
```

Expected: wpłata `"balance":"500.00"`; 1000 → `{"error":"Insufficient funds in wallet …"}`; ten sam portfel → `{"error":"Cannot transfer to the same wallet."}`; 100 → `"status":"pending"`, `"toAmount":"23.43"`; lista: PLN `"balance":"500.00","reserved":"100.00","available":"400.00"`, EUR `"balance":"0.00"`.

- [ ] **Step 6: Commit**

```bash
git add src/Exception/SameWalletTransferException.php src/Service/TransferService.php src/Controller/WalletController.php tests/Service/TransferServiceTest.php tests/Controller/WalletControllerTest.php
git commit -m "feat: reserve funds when a transfer is placed

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Realizacja i odrzucenie rozliczają rezerwację; spread do firmy; koniec `setBalance`

**Files:**
- Create: `tests/Command/ProcessTransactionsCommandTest.php`
- Modify: `src/Service/TransactionProcessorService.php`, `src/Command/ProcessTransactionsCommand.php`, `src/Entity/Wallet.php`, `src/Entity/CompanyWallet.php`
- Test: `tests/Service/TransactionProcessorServiceTest.php` (pełne przepisanie), `tests/Entity/WalletTest.php`, `tests/Entity/CompanyWalletTest.php`, `tests/Dto/WalletResponseTest.php`, `tests/Controller/WalletControllerTest.php`

**Interfaces:**
- Consumes: `Wallet::settle()/credit()/release()`, `CompanyWalletRepositoryInterface::addToBalance(Money)`, `TransactionManagerInterface`, `lockForUpdate()`, `TransactionAlreadyProcessedException`.
- Produces:
  - `new TransactionProcessorService(WalletRepositoryInterface, TransactionRepositoryInterface, CompanyWalletRepositoryInterface, TransactionManagerInterface)`.
  - `complete()` / `reject()` mogą rzucić `TransactionAlreadyProcessedException` (cała operacja cofnięta).
  - `Wallet::setBalance()` i `CompanyWallet::setBalance()` **usunięte**; `CompanyWallet::credit(Money): void`.

- [ ] **Step 1: Write the failing tests**

`tests/Service/TransactionProcessorServiceTest.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
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
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class TransactionProcessorServiceTest extends TestCase
{
    private WalletRepositoryInterface $walletRepository;
    private TransactionRepositoryInterface $transactionRepository;
    private CompanyWalletRepositoryInterface $companyWalletRepository;
    private ImmediateTransactionManager $transactionManager;
    private TransactionProcessorService $transactionProcessorService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->companyWalletRepository = $this->createMock(CompanyWalletRepositoryInterface::class);
        $this->transactionManager = new ImmediateTransactionManager();

        $this->transactionProcessorService = new TransactionProcessorService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->companyWalletRepository,
            $this->transactionManager,
        );
    }

    public function testCompleteSettlesReservationCreditsTargetAndBooksSpread(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00');
        $toWallet = WalletFixture::create(2, 1, Currency::EUR, '100.00');
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);
        $this->givenWallets($fromWallet, $toWallet);

        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(1, 2);
        $this->walletRepository
            ->expects(self::exactly(2))
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));
        $this->companyWalletRepository
            ->expects(self::once())
            ->method('addToBalance')
            ->with($this->callback(static fn (Money $spread): bool => Currency::EUR === $spread->getCurrency() && '0.50' === $spread->toString()));
        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);

        $this->transactionProcessorService->complete($transaction);

        self::assertSame('400.00', $fromWallet->getBalance()->toString());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
        self::assertSame('125.00', $toWallet->getBalance()->toString());
        self::assertNotNull($fromWallet->getLastActivityAt());
        self::assertNotNull($toWallet->getLastActivityAt());
        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
        self::assertSame(1, $this->transactionManager->calls);
    }

    public function testCompleteSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00'), WalletFixture::create(2, 1, Currency::EUR));
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteDoesNotBookZeroSpread(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::USD, '50.00', '50.00'), WalletFixture::create(2, 1, Currency::USD));
        $transaction = Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('50.00', Currency::USD),
            toAmount: Money::of('50.00', Currency::USD),
            spread: Money::zero(Currency::USD),
            exchangeRate: ExchangeRate::of(Currency::USD, Currency::USD, '1'),
            requiresAntiFraudCheck: false,
        );

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::COMPLETED, $transaction->getStatus());
    }

    public function testCompleteRejectsAndReleasesWhenSourceWalletBlocked(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00', blocked: true);
        $toWallet = WalletFixture::create(2, 1, Currency::EUR, '100.00');
        $this->givenWallets($fromWallet, $toWallet);
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
        self::assertSame('100.00', $toWallet->getBalance()->toString());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testCompleteRejectsAndReleasesWhenTargetWalletBlocked(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00');
        $toWallet = WalletFixture::create(2, 1, Currency::EUR, '100.00', blocked: true);
        $this->givenWallets($fromWallet, $toWallet);
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->companyWalletRepository->expects(self::never())->method('addToBalance');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
        self::assertSame('100.00', $toWallet->getBalance()->toString());
    }

    public function testCompleteRejectsWhenFromWalletNotFound(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, null],
                [2, WalletFixture::create(2, 1, Currency::EUR)],
            ]);

        $this->walletRepository->expects(self::never())->method('save');

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
    }

    public function testCompleteRejectsAndReleasesWhenToWalletNotFound(): void
    {
        $fromWallet = WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00');
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, null],
            ]);

        $this->walletRepository
            ->expects(self::once())
            ->method('save')
            ->with(self::identicalTo($fromWallet));

        $this->transactionProcessorService->complete($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertSame('0.00', $fromWallet->getReserved()->toString());
        self::assertSame('500.00', $fromWallet->getBalance()->toString());
    }

    public function testCompletePropagatesAlreadyProcessed(): void
    {
        $this->givenWallets(WalletFixture::create(1, 1, Currency::PLN, '500.00', '100.00'), WalletFixture::create(2, 1, Currency::EUR));
        $this->transactionRepository
            ->method('save')
            ->willThrowException(new TransactionAlreadyProcessedException(7));

        $this->expectException(TransactionAlreadyProcessedException::class);

        $this->transactionProcessorService->complete($this->makeTransaction(requiresAntiFraudCheck: false));
    }

    public function testRejectSetsRejectedStatus(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: false);

        $wallet = $this->createMock(Wallet::class);
        $wallet
            ->expects($this->once())
            ->method('release')
            ->with($this->callback(static fn (Money $amount): bool => Currency::PLN === $amount->getCurrency() && '100.00' === $amount->toString()));

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($transaction);
        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($wallet);
        $this->walletRepository
            ->expects($this->once())
            ->method('save')
            ->with($wallet);

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRejectSetsAntiFraudCheckedAtWhenRequired(): void
    {
        $transaction = $this->makeTransaction(requiresAntiFraudCheck: true);

        $this->transactionProcessorService->reject($transaction);

        self::assertSame(TransactionStatus::REJECTED, $transaction->getStatus());
        self::assertNotNull($transaction->getAntiFraudCheckedAt());
    }

    public function testRejectLocksSourceWallet(): void
    {
        $this->walletRepository->expects(self::once())->method('lockForUpdate')->with(1);

        $this->transactionProcessorService->reject($this->makeTransaction(requiresAntiFraudCheck: false));

        self::assertSame(1, $this->transactionManager->calls);
    }

    private function givenWallets(Wallet $fromWallet, Wallet $toWallet): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);
    }

    private function makeTransaction(bool $requiresAntiFraudCheck): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('25.00', Currency::EUR),
            spread: Money::of('0.50', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
    }
}
```

`tests/Command/ProcessTransactionsCommandTest.php`:

```php
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

    private function makePendingTransaction(int $id): Transaction
    {
        return new Transaction(
            id: $id,
            fromWalletId: 1,
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
```

(`willReturnCallback` w `findById` tworzy świeże portfele przy każdym wywołaniu — każda transakcja ma własną rezerwację 200.00 ≥ 100.00.)

Usuń `setBalance` z pozostałych testów:

- `tests/Entity/WalletTest.php` — **usuń** `testSetBalance` i `testSetBalanceRejectsDifferentCurrency` (pokrywają je `testCreditIncreasesBalance` i `testOperationsRejectOtherCurrency`).
- `tests/Entity/CompanyWalletTest.php` — zastąp `testSetBalance`, `testSetBalanceToZero`, `testSetBalanceRejectsDifferentCurrency` (dodaj `use InvalidArgumentException;`):

```php
    public function testCredit(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);
        $wallet->credit(Money::of('250.75', Currency::PLN));
        $wallet->credit(Money::of('0.25', Currency::PLN));

        $this->assertSame('251.00', $wallet->getBalance()->toString());
    }

    public function testCreditRejectsNonPositiveAmount(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive.');

        $wallet->credit(Money::zero(Currency::PLN));
    }

    public function testCreditRejectsDifferentCurrency(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);

        $this->expectException(CurrencyMismatchException::class);

        $wallet->credit(Money::of('1.00', Currency::EUR));
    }
```

- `tests/Dto/WalletResponseTest.php` — w `testJsonSerializeBalanceAsStringInCurrencyScale` zamień `$wallet->setBalance(Money::of('1250', Currency::JPY));` na `$wallet->credit(Money::of('1250', Currency::JPY));`.
- `tests/Controller/WalletControllerTest.php` — w `testListReturnsBalancesAsStrings` zamień `$wallet->setBalance(Money::of('1250.5', Currency::PLN));` na `$wallet->credit(Money::of('1250.5', Currency::PLN));`.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Service/TransactionProcessorServiceTest.php tests/Command tests/Entity/CompanyWalletTest.php`
Expected: Errors — za dużo argumentów konstruktora `TransactionProcessorService`, `Call to undefined method App\Entity\CompanyWallet::credit()`.

- [ ] **Step 3: Implement**

`src/Service/TransactionProcessorService.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\TransactionStatus;
use App\Exception\TransactionAlreadyProcessedException;
use App\Repository\CompanyWalletRepositoryInterface;
use App\Repository\TransactionManagerInterface;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use DateTimeImmutable;

final readonly class TransactionProcessorService
{
    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private CompanyWalletRepositoryInterface $companyWalletRepository,
        private TransactionManagerInterface $transactionManager,
    ) {
    }

    /**
     * Settles the reservation on the source wallet, credits the target and books the spread for the company.
     * A missing or blocked wallet rejects the transaction instead.
     *
     * @throws TransactionAlreadyProcessedException
     */
    public function complete(Transaction $transaction): void
    {
        $this->transactionManager->transactional(function () use ($transaction): void {
            $this->walletRepository->lockForUpdate($transaction->getFromWalletId(), $transaction->getToWalletId());

            $fromWallet = $this->walletRepository->findById($transaction->getFromWalletId());
            $toWallet = $this->walletRepository->findById($transaction->getToWalletId());

            if (null === $fromWallet || null === $toWallet || $fromWallet->isBlocked() || $toWallet->isBlocked()) {
                $this->rejectLocked($transaction, $fromWallet);

                return;
            }

            $now = new DateTimeImmutable();

            $fromWallet->settle($transaction->getFromAmount());
            $fromWallet->setLastActivityAt($now);

            $toWallet->credit($transaction->getToAmount());
            $toWallet->setLastActivityAt($now);

            $this->walletRepository->save($fromWallet);
            $this->walletRepository->save($toWallet);

            $spread = $transaction->getSpread();
            if ($spread->isGreaterThan(Money::zero($spread->getCurrency()))) {
                $this->companyWalletRepository->addToBalance($spread);
            }

            $transaction->setStatus(TransactionStatus::COMPLETED);
            $this->markAntiFraudChecked($transaction);
            $this->transactionRepository->save($transaction);
        });
    }

    /**
     * Releases the reservation on the source wallet.
     *
     * @throws TransactionAlreadyProcessedException
     */
    public function reject(Transaction $transaction): void
    {
        $this->transactionManager->transactional(function () use ($transaction): void {
            $this->walletRepository->lockForUpdate($transaction->getFromWalletId());

            $this->rejectLocked($transaction, $this->walletRepository->findById($transaction->getFromWalletId()));
        });
    }

    private function rejectLocked(Transaction $transaction, ?Wallet $fromWallet): void
    {
        if (null !== $fromWallet) {
            $fromWallet->release($transaction->getFromAmount());
            $this->walletRepository->save($fromWallet);
        }

        $transaction->setStatus(TransactionStatus::REJECTED);
        $this->markAntiFraudChecked($transaction);
        $this->transactionRepository->save($transaction);
    }

    private function markAntiFraudChecked(Transaction $transaction): void
    {
        if ($transaction->requiresAntiFraudCheck()) {
            $transaction->setAntiFraudCheckedAt(new DateTimeImmutable());
        }
    }
}
```

`src/Command/ProcessTransactionsCommand.php` — import `use App\Exception\TransactionAlreadyProcessedException;`; pętla `pending`:

```php
        foreach ($pending as $transaction) {
            try {
                $this->transactionProcessorService->complete($transaction);
            } catch (TransactionAlreadyProcessedException) {
                $io->warning(sprintf('Transaction #%d was already processed, skipped.', $transaction->getId()));

                continue;
            }

            if (TransactionStatus::COMPLETED === $transaction->getStatus()) {
                $io->success(sprintf('Transaction #%d completed.', $transaction->getId()));
            } else {
                $io->warning(sprintf('Transaction #%d rejected (wallet not found or blocked).', $transaction->getId()));
            }
        }
```

w pętli `fraudReview` zastąp blok od `$approved = ...` do końca `if/else`:

```php
            $approved = $io->confirm('Approve this transaction?');

            try {
                if ($approved) {
                    $this->transactionProcessorService->complete($transaction);
                } else {
                    $this->transactionProcessorService->reject($transaction);
                }
            } catch (TransactionAlreadyProcessedException) {
                $io->warning(sprintf('Transaction #%d was already processed, skipped.', $transaction->getId()));

                continue;
            }

            if (!$approved) {
                $io->warning(sprintf('Transaction #%d rejected.', $transaction->getId()));
            } elseif (TransactionStatus::COMPLETED === $transaction->getStatus()) {
                $io->success(sprintf('Transaction #%d approved and completed.', $transaction->getId()));
            } else {
                $io->warning(sprintf('Transaction #%d rejected (wallet not found or blocked).', $transaction->getId()));
            }
```

`src/Entity/Wallet.php` — **usuń** metodę `setBalance()`.

`src/Entity/CompanyWallet.php` — zamień `setBalance()` na (import `use InvalidArgumentException;`):

```php
    public function credit(Money $amount): void
    {
        $this->assertBalanceCurrency($amount);

        if (!$amount->isGreaterThan(Money::zero($this->currency))) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        $this->balance = $this->balance->add($amount);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service tests/Command tests/Entity tests/Dto tests/Controller && grep -rn "setBalance" src tests; php bin/console lint:container`
Expected: `OK` — w tym `testRejectSetsRejectedStatus` (wcześniej failujący); `grep` nic nie znajduje; container OK.

- [ ] **Step 5: Verify live**

```bash
php bin/console app:company-wallet
TOKEN=$(php bin/console app:create-user | grep -oE '[a-f0-9]{64}' | tail -1); A="Authorization: Bearer $TOKEN"; U=https://127.0.0.1:8000/api/wallets
P=$(curl -sk -X POST -H "$A" -d '{"currency":"PLN"}' $U | grep -oE '"id":[0-9]+' | cut -d: -f2)
E=$(curl -sk -X POST -H "$A" -d '{"currency":"EUR"}' $U | grep -oE '"id":[0-9]+' | cut -d: -f2)
curl -sk -X POST -H "$A" -d '{"amount":"500.00"}' $U/$P/deposit; echo
curl -sk -X POST -H "$A" -d "{\"fromWalletId\":$P,\"toWalletId\":$E,\"amount\":\"100.00\"}" $U/transfer; echo
php bin/console app:process-transactions -n
php bin/console app:company-wallet
curl -sk -H "$A" $U; echo
```

Uwaga: w trybie `-n` pytanie fraud review dostaje odpowiedź domyślną, czyli **„tak”** — przetworzy też wszystkie inne `pending`/`fraud_review` z deweloperskiej bazy (w tym 4 przeniesione migracją). To dane deweloperskie, więc jest to akceptowalne.

Expected: w wyjściu komendy `Transaction #… completed.` dla nowego przelewu; saldo EUR w `app:company-wallet` wzrosło o co najmniej `0.16` względem pierwszego wywołania; lista: PLN `"balance":"400.00","reserved":"0.00","available":"400.00"`, EUR `"balance":"23.43"`.

- [ ] **Step 6: Commit**

```bash
git add src/Service/TransactionProcessorService.php src/Command/ProcessTransactionsCommand.php src/Entity/Wallet.php src/Entity/CompanyWallet.php tests/Service/TransactionProcessorServiceTest.php tests/Command tests/Entity/WalletTest.php tests/Entity/CompanyWalletTest.php tests/Dto/WalletResponseTest.php tests/Controller/WalletControllerTest.php
git commit -m "feat: settle or release reservations and book spread on processing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Test integracyjny pełnego cyklu, dokumentacja, pełny suite

**Files:**
- Create: `tests/Integration/TransferFlowTest.php`, `docs/business-logic.md`
- Modify: `README.md`

**Interfaces:**
- Consumes: wszystko z Tasków 1–6, `DatabaseTestCase`.

- [ ] **Step 1: Write the integration test**

`tests/Integration/TransferFlowTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InsufficientFundsException;
use App\Exception\TransactionAlreadyProcessedException;
use App\Repository\CompanyWalletRepository;
use App\Repository\TransactionRepository;
use App\Repository\WalletRepository;
use App\Service\TransactionProcessorService;
use App\Service\TransferService;
use App\ValueObject\Money;

class TransferFlowTest extends DatabaseTestCase
{
    public function testCompletedTransferMovesMoneyOnceAndBooksSpread(): void
    {
        $user = $this->createUser();
        $pln = (int) $this->createWallet($user, Currency::PLN, '500.00')->getId();
        $eur = (int) $this->createWallet($user, Currency::EUR)->getId();
        $companyBefore = $this->companyBalance(Currency::EUR);

        $placed = $this->service(TransferService::class)->transfer($user->getIdNotNull(), $pln, $eur, '100.00');

        $this->assertWallet($pln, '500.00', '100.00');
        $this->assertWallet($eur, '0.00', '0.00');

        $this->service(TransactionProcessorService::class)->complete($this->reload((int) $placed->getId()));

        $this->assertWallet($pln, '400.00', '0.00');
        $this->assertWallet($eur, '23.43', '0.00');
        self::assertSame($companyBefore->add(Money::of('0.16', Currency::EUR))->toString(), $this->companyBalance(Currency::EUR)->toString());
        self::assertSame(TransactionStatus::COMPLETED, $this->reload((int) $placed->getId())->getStatus());
    }

    public function testRejectedTransferLeavesBalancesUntouched(): void
    {
        $user = $this->createUser();
        $pln = (int) $this->createWallet($user, Currency::PLN, '500.00')->getId();
        $eur = (int) $this->createWallet($user, Currency::EUR)->getId();
        $companyBefore = $this->companyBalance(Currency::EUR);

        $placed = $this->service(TransferService::class)->transfer($user->getIdNotNull(), $pln, $eur, '100.00');
        $this->service(TransactionProcessorService::class)->reject($this->reload((int) $placed->getId()));

        $this->assertWallet($pln, '500.00', '0.00');
        $this->assertWallet($eur, '0.00', '0.00');
        self::assertSame($companyBefore->toString(), $this->companyBalance(Currency::EUR)->toString());
        self::assertSame(TransactionStatus::REJECTED, $this->reload((int) $placed->getId())->getStatus());
    }

    public function testTransactionCannotBeSettledTwice(): void
    {
        $user = $this->createUser();
        $pln = (int) $this->createWallet($user, Currency::PLN, '500.00')->getId();
        $eur = (int) $this->createWallet($user, Currency::EUR)->getId();
        $companyBefore = $this->companyBalance(Currency::EUR);

        $placed = $this->service(TransferService::class)->transfer($user->getIdNotNull(), $pln, $eur, '100.00');
        $firstWorkerCopy = $this->reload((int) $placed->getId());
        $secondWorkerCopy = $this->reload((int) $placed->getId());

        $this->service(TransactionProcessorService::class)->complete($firstWorkerCopy);

        try {
            $this->service(TransactionProcessorService::class)->complete($secondWorkerCopy);
            self::fail('Expected TransactionAlreadyProcessedException.');
        } catch (TransactionAlreadyProcessedException) {
        }

        $this->assertWallet($pln, '400.00', '0.00');
        $this->assertWallet($eur, '23.43', '0.00');
        self::assertSame($companyBefore->add(Money::of('0.16', Currency::EUR))->toString(), $this->companyBalance(Currency::EUR)->toString());
    }

    public function testInsufficientFundsChangesNothing(): void
    {
        $user = $this->createUser();
        $pln = (int) $this->createWallet($user, Currency::PLN, '50.00')->getId();
        $eur = (int) $this->createWallet($user, Currency::EUR)->getId();

        try {
            $this->service(TransferService::class)->transfer($user->getIdNotNull(), $pln, $eur, '100.00');
            self::fail('Expected InsufficientFundsException.');
        } catch (InsufficientFundsException) {
        }

        $this->assertWallet($pln, '50.00', '0.00');
        self::assertSame([], $this->service(TransactionRepository::class)->findByWalletId($pln));
    }

    private function reload(int $transactionId): Transaction
    {
        $transaction = $this->service(TransactionRepository::class)->findById($transactionId);
        self::assertNotNull($transaction);

        return $transaction;
    }

    private function assertWallet(int $walletId, string $balance, string $reserved): void
    {
        $wallet = $this->service(WalletRepository::class)->findById($walletId);

        self::assertSame($balance, $wallet?->getBalance()->toString(), 'balance');
        self::assertSame($reserved, $wallet?->getReserved()->toString(), 'reserved');
    }

    private function companyBalance(Currency $currency): Money
    {
        return $this->service(CompanyWalletRepository::class)->findByCurrency($currency)?->getBalance() ?? Money::zero($currency);
    }
}
```

- [ ] **Step 2: Run it**

Run: `php bin/phpunit tests/Integration/TransferFlowTest.php`
Expected: `OK (4 tests, …)`. (Test pisany po implementacji — weryfikuje integrację warstw na prawdziwej bazie; jeśli coś failuje, to błąd w Taskach 2–6 do naprawienia przez superpowers:systematic-debugging.)

- [ ] **Step 3: Business logic documentation**

`docs/business-logic.md`:

~~~~markdown
# Business logic

How money moves in Exchange: wallets, transfers, processing, deposits and the company's earnings.

## Wallet balances

Every wallet holds money in one currency and has three amounts:

| Amount      | Meaning                                                                    |
|-------------|----------------------------------------------------------------------------|
| `balance`   | money that is in the wallet                                                |
| `reserved`  | money promised to outgoing transfers that are still waiting for processing |
| `available` | `balance − reserved` — what the owner can still spend or transfer          |

Amounts are kept with the currency's precision: 0 decimal places for JPY, 2 for all other currencies.
An amount with more decimal places than that is rejected, never rounded.

## Transfers

A transfer moves money between two wallets of the same user, converting the currency if needed.

### Placing a transfer — `POST /api/wallets/transfer`

Checks, in this order (the first one that fails stops the transfer):

| Check                                           | Response                                   |
|-------------------------------------------------|--------------------------------------------|
| source and target are different wallets         | `400` Cannot transfer to the same wallet.  |
| both wallets exist and belong to the user       | `404` Wallet {id} not found.               |
| amount is positive and fits the currency        | `400`                                      |
| target wallet is not blocked                    | `422` Wallet {id} is blocked.              |
| source wallet is not blocked                    | `422` Wallet {id} is blocked.              |
| amount ≤ available funds of the source          | `422` Insufficient funds in wallet {id}.   |

When all checks pass, the amount is **reserved** on the source wallet — no balance changes yet.
The transaction is created with the exchange rate, the spread and the amount the target will receive:

    gross    = amount × rate            (rounded to the target currency precision)
    spread   = gross × 0.5% ÷ average liquidity of the pair
    toAmount = gross − spread

A transfer whose source amount is greater than the anti-fraud threshold of the source currency waits for manual
review (`fraud_review`); every other transfer is `pending`.

### Processing — `app:process-transactions`

`pending` transfers are completed automatically; for each `fraud_review` transfer the operator approves (complete)
or rejects it.

```
transfer ──► PENDING ──────► complete ──► COMPLETED
        └──► FRAUD_REVIEW ─┬─► complete ──► COMPLETED
                           └─► reject ────► REJECTED
             (PENDING can be rejected too when a wallet is missing or blocked)
```

| Operation  | Source `balance` | Source `reserved` | Target `balance` | Company wallet (target currency) |
|------------|------------------|-------------------|------------------|----------------------------------|
| transfer   | —                | `+ amount`        | —                | —                                |
| complete   | `− amount`       | `− amount`        | `+ toAmount`     | `+ spread`                       |
| reject     | —                | `− amount`        | —                | —                                |

- A transfer is completed only if both wallets still exist and neither is blocked; otherwise it is rejected and
  the reservation is released.
- `COMPLETED` and `REJECTED` are final. Processing the same transaction twice (e.g. two runs of the command at the
  same time) settles it once — the second attempt is refused and changes nothing.

## Deposits — `POST /api/wallets/{id}/deposit`

A deposit adds money to the wallet's balance. It is refused when the wallet does not exist or belongs to someone
else (`404`), the amount does not fit the currency (`400`), the amount is above the currency's deposit limit
(`400` Amount cannot exceed {limit} {CUR}.), or the wallet is blocked (`422`).

## Limits

Configured per currency in `config/services.yaml` (`app.limits.*`):

| Currency | Anti-fraud threshold (transfer amount above it → review) | Max single deposit |
|----------|---------------------------------------------------------:|-------------------:|
| PLN      | 15 000                                                   | 10 000             |
| EUR      | 3 500                                                    | 2 500              |
| USD      | 4 000                                                    | 2 500              |
| GBP      | 3 000                                                    | 2 000              |
| CHF      | 3 200                                                    | 2 000              |
| JPY      | 650 000                                                  | 450 000            |
| HUF      | 1 250 000                                                | 850 000            |

## Company earnings

The company earns the spread of every **completed** transfer, in the target currency. Earnings are kept in
`company_wallets` (one row per currency) and shown by `app:company-wallet`. Rejected transfers earn nothing.

## Consistency

Every operation that changes balances runs in one database transaction and locks the affected wallets first
(always in ascending id order, so two opposite transfers cannot deadlock). If anything fails, nothing is saved.
~~~~

`README.md`:
- w sekcji `## About`, po akapicie o `app:company-wallet`, dodaj: `How transfers, reservations, processing and limits work is described in [docs/business-logic.md](docs/business-logic.md).`
- w tabeli endpointów: deposit → `Deposit funds into a wallet. Body: { "amount": "500.00" }. The maximum single deposit depends on the currency (see docs/business-logic.md). Returns 422 if the wallet is blocked.`; transfer → `Transfer funds between two different wallets of the authenticated user (currency exchange supported). Body: { "fromWalletId": 1, "toWalletId": 2, "amount": "100.00" }. Reserves the amount until the transfer is processed. Returns 422 when funds are insufficient or a wallet is blocked.`; list → dopisz `Each wallet shows balance, reserved and available amounts.`
- w tabeli komend `app:process-transactions` → `Processes pending transfers and asks for a decision on those in fraud review; completes or rejects them.`

- [ ] **Step 4: Full suite, style, no leftovers**

Run:

```bash
php bin/phpunit
vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php --path-mode=intersection --dry-run --diff $(git diff --name-only 9aeef05 -- src tests migrations config)
grep -rn "MAX_AMOUNT\|ANTI_FRAUD_THRESHOLD\|setBalance" src tests
```

Expected: `OK` — **0 failures** (w tym oba dawniej failujące testy); cs-fixer bez diffów (jeśli są — `fix` na tych plikach, ponowny `phpunit`, dołącz do commita); `grep` nic nie znajduje.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/TransferFlowTest.php docs/business-logic.md README.md
git commit -m "docs: describe business logic; test full transfer flow on a real database

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
