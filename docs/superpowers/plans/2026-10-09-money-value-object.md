# Money Value Object Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zastąpić `float`/`string` dla kwot i kursów obiektami `Money` i `ExchangeRate` opartymi o `BcMath\Number`, z precyzją zależną od waluty, bez zmiany logiki biznesowej.

**Architecture:** Dwa niemutowalne value objecty w `src/ValueObject/` (`Money`, `ExchangeRate`) plus `Currency::scale()`. Encje trzymają `Money`/`ExchangeRate`, repozytoria (DBAL, ręczny SQL) mapują je na kolumny `DECIMAL` przez `Money::of()` / `toString()`, DTO serializują kwoty jako stringi. Zaokrąglenie wyłącznie jawne (`RoundingMode::HalfAwayFromZero`) przy przewalutowaniu i procencie.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine DBAL 4 + Migrations, MariaDB 11.4, PHPUnit 13, rozszerzenie `bcmath` (`BcMath\Number`, `RoundingMode`).

**Spec:** `docs/superpowers/specs/2026-10-09-money-value-object-design.md`

## Global Constraints

- Brak zewnętrznych bibliotek do pieniędzy; tylko `bcmath` (`BcMath\Number`).
- Skala kwot: `JPY` = 0, pozostałe waluty = 2 (`Currency::scale()`).
- Skala kursu: 6 (`ExchangeRate::SCALE`), kurs zaokrąglany przed użyciem.
- Tryb zaokrąglania: `RoundingMode::HalfAwayFromZero`, zawsze przekazywany jawnie w kodzie domenowym.
- Kwota z wejścia z precyzją większą niż skala waluty → `InvalidMoneyAmountException` (nie zaokrąglamy). Nadmiarowe **zera** są dozwolone (`"12.5000"` dla PLN OK).
- Format kwoty: `^-?\d+(\.\d+)?$` z modyfikatorem `D` (bez końcowego `\n`).
- API: kwoty w odpowiedziach jako stringi w skali waluty; `exchangeRate` string z 6 miejscami. W requestach `amount` jako string **lub** liczba JSON.
- Zakres: **bez zmian logiki biznesowej** (podwójne księgowanie, brak kontroli salda itd. zostają).
- Kolumny kwot w bazie: `DECIMAL(15,4)` (także `wallets.balance` po migracji).
- Styl: `declare(strict_types=1)`, `@Symfony` php-cs-fixer z `global_namespace_import` (importy `use BcMath\Number;`, `use RoundingMode;`), asercje w testach jak w otaczającym pliku (`self::assert...` / `$this->assert...`).
- Commity: tylko pliki z danego taska (w drzewie są niezacommitowane zmiany użytkownika w `.env` — **nigdy** go nie dodawaj). Każdy commit kończy się linią `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Stan wyjściowy testów (ważne)

Na HEAD `php bin/phpunit` daje **96 testów, 2 failures** — oba opisują zachowanie z przyszłej poprawki podwójnego księgowania (poza zakresem):

1. `TransactionProcessorServiceTest::testRejectSetsRejectedStatus` — oczekuje, że `reject()` pobierze i zapisze portfel.
2. `TransferServiceTest::testTransferSuccessfully` — oczekuje, że `transfer()` **nie** wywoła `setBalance`.

Plan **zachowuje** oba testy (przepisane na `Money`) i one **dalej failują** z tym samym powodem. Kryterium końcowe: wszystko zielone poza tymi dwoma.

Od Taska 4 do końca Taska 7 pełny suite będzie czerwony (encje zmieniają typy, a nie wszyscy konsumenci są już przepisani) — w każdym tasku uruchamiaj testy wskazane w krokach, pełny suite dopiero w Tasku 9.

## Review Focus

1. **Kwota z końcowym znakiem nowej linii** (`"100\n"`) — `$` w PCRE dopasowuje przed końcowym `\n`; oczekiwane: odrzucenie. Test w Tasku 2 (`MoneyTest`, provider `invalidFormatProvider`).
2. **Ujemne zero / zera wiodące** (`"-0.00"`, `"007.5"`) — oczekiwane: `"0.00"` i `"7.50"`, bez `"-0.00"`. Test w Tasku 2 (`testOfNormalizesNegativeZeroAndLeadingZeros`).
3. **Odczyt `DECIMAL(15,4)` z bazy** (`"8385.3700"`, JPY `"4333.0000"`) — oczekiwane: poprawny `Money`, nie wyjątek. Test w Tasku 2 (`testOfAcceptsTrailingZerosBeyondScale`, przypadek JPY).
4. **Liczba JSON w notacji wykładniczej lub ujemne zero** (`1e25`, `-0.0`) — oczekiwane: `400`, serwis nie wywołany. Test w Tasku 8 (`invalidAmountProvider`).
5. **Spójność walut w transakcji odczytanej z bazy** — spread w innej walucie niż `toAmount` lub kurs z złymi walutami → wyjątek przy budowie encji, a nie cicho zapisane bzdury. Test w Tasku 5 (`testConstructorRejects...`).

---

## File Structure

**Create:**
- `src/ValueObject/Money.php` — kwota + waluta, arytmetyka, parsowanie, formatowanie.
- `src/ValueObject/ExchangeRate.php` — kurs `from → to` w skali 6.
- `src/Exception/InvalidMoneyAmountException.php` — błąd wejścia (→ 400).
- `src/Exception/CurrencyMismatchException.php` — błąd programisty (niełapany).
- `migrations/Version20261009120000.php` — `wallets.balance` → `DECIMAL(15,4)` + zaokrąglenie danych.
- `tests/Enum/CurrencyTest.php`, `tests/ValueObject/MoneyTest.php`, `tests/ValueObject/ExchangeRateTest.php`.

**Modify:**
- `src/Enum/Currency.php` — `scale()`.
- `src/Entity/Wallet.php`, `src/Entity/CompanyWallet.php`, `src/Entity/Transaction.php`.
- `src/Repository/WalletRepository.php`, `src/Repository/CompanyWalletRepository.php`, `src/Repository/CompanyWalletRepositoryInterface.php`, `src/Repository/TransactionRepository.php`.
- `src/Service/ExchangeRateService.php`, `src/Service/SpreadService.php`, `src/Service/TransferService.php`, `src/Service/DepositService.php`, `src/Service/TransactionProcessorService.php`.
- `src/Dto/WalletResponse.php`, `src/Dto/TransactionResponse.php`.
- `src/Command/ShowCompanyWalletCommand.php`, `src/Command/ProcessTransactionsCommand.php`.
- `src/Controller/WalletController.php`.
- `Dockerfile`, `composer.json`, `composer.lock` (hash), `README.md`.
- Testy: `tests/Entity/{Wallet,CompanyWallet,Transaction}Test.php`, `tests/Dto/*`, `tests/Service/{ExchangeRate,Spread,Deposit,Transfer,TransactionProcessor}ServiceTest.php`, `tests/Controller/WalletControllerTest.php`.

`exchange-api.postman_collection.json` — bez zmian (body już mają kwoty jako stringi, brak przykładowych odpowiedzi).

---

### Task 1: bcmath w środowisku + `Currency::scale()`

**Files:**
- Modify: `Dockerfile`, `composer.json`, `composer.lock`, `src/Enum/Currency.php`
- Create: `tests/Enum/CurrencyTest.php`

**Interfaces:**
- Produces: `Currency::scale(): int` (JPY → 0, reszta → 2).

- [ ] **Step 1: Write the failing test**

`tests/Enum/CurrencyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Currency;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CurrencyTest extends TestCase
{
    #[DataProvider('scaleProvider')]
    public function testScale(Currency $currency, int $expectedScale): void
    {
        self::assertSame($expectedScale, $currency->scale());
    }

    public static function scaleProvider(): Generator
    {
        yield 'PLN' => [Currency::PLN, 2];
        yield 'EUR' => [Currency::EUR, 2];
        yield 'USD' => [Currency::USD, 2];
        yield 'GBP' => [Currency::GBP, 2];
        yield 'JPY' => [Currency::JPY, 0];
        yield 'CHF' => [Currency::CHF, 2];
        yield 'HUF' => [Currency::HUF, 2];
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php bin/phpunit tests/Enum/CurrencyTest.php`
Expected: FAIL / Error `Call to undefined method App\Enum\Currency::scale()`

- [ ] **Step 3: Implement**

`src/Enum/Currency.php` — dodaj metodę pod ostatnim `case`:

```php
    case HUF = 'HUF'; // Hungarian Forint

    /**
     * Number of decimal places (ISO 4217 minor units).
     */
    public function scale(): int
    {
        return match ($this) {
            self::JPY => 0,
            default => 2,
        };
    }
```

`Dockerfile` — dopisz `bcmath` do listy rozszerzeń:

```dockerfile
RUN docker-php-ext-install \
    bcmath \
    intl \
    zip \
    pdo_mysql \
    mysqli \
    gd
```

`composer.json` — w `require` dodaj (alfabetycznie po `php`):

```json
        "php": "^8.5",
        "ext-bcmath": "*",
        "doctrine/dbal": "^4.4",
```

- [ ] **Step 4: Update lock hash and verify**

Run: `composer update --lock && php -m | grep -i bcmath && php bin/phpunit tests/Enum/CurrencyTest.php`
Expected: `bcmath` wypisane, `OK (7 tests, 7 assertions)`. `git diff composer.lock` pokazuje tylko zmianę `content-hash`.

- [ ] **Step 5: Commit**

```bash
git add Dockerfile composer.json composer.lock src/Enum/Currency.php tests/Enum/CurrencyTest.php
git commit -m "feat: add Currency::scale() and require bcmath

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Wyjątki + `ExchangeRate` + `Money`

**Files:**
- Create: `src/Exception/InvalidMoneyAmountException.php`, `src/Exception/CurrencyMismatchException.php`, `src/ValueObject/ExchangeRate.php`, `src/ValueObject/Money.php`
- Test: `tests/ValueObject/ExchangeRateTest.php`, `tests/ValueObject/MoneyTest.php`

**Interfaces:**
- Consumes: `Currency::scale()`.
- Produces:
  - `InvalidMoneyAmountException::invalidFormat(string $amount): self`, `::tooManyDecimalPlaces(Currency $currency): self` — komunikat `Amount has too many decimal places for PLN.`
  - `new CurrencyMismatchException(Currency $expected, Currency $actual)` — komunikat `Currency mismatch: expected PLN, got EUR.`
  - `ExchangeRate::SCALE = 6`; `ExchangeRate::of(Currency $from, Currency $to, Number|string $rate): self`; `getFrom(): Currency`, `getTo(): Currency`, `getRate(): Number`, `toString(): string`.
  - `Money::of(string $amount, Currency $currency): self`, `Money::zero(Currency): self`, `Money::isValidFormat(string): bool`, `add(Money): Money`, `subtract(Money): Money`, `convertTo(Currency $target, ExchangeRate $rate, RoundingMode $mode): Money`, `percentage(Number $percent, RoundingMode $mode): Money`, `isGreaterThan(Money): bool`, `equals(Money): bool`, `getCurrency(): Currency`, `toString(): string`.

- [ ] **Step 1: Write the failing tests**

`tests/ValueObject/ExchangeRateTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\Enum\Currency;
use App\ValueObject\ExchangeRate;
use BcMath\Number;
use PHPUnit\Framework\TestCase;

class ExchangeRateTest extends TestCase
{
    public function testOfRoundsToSixDecimalPlaces(): void
    {
        $rate = ExchangeRate::of(Currency::PLN, Currency::EUR, '0.2359102597');

        self::assertSame('0.235910', $rate->toString());
        self::assertSame(Currency::PLN, $rate->getFrom());
        self::assertSame(Currency::EUR, $rate->getTo());
    }

    public function testOfPadsToSixDecimalPlaces(): void
    {
        self::assertSame('4.238900', ExchangeRate::of(Currency::EUR, Currency::PLN, '4.2389')->toString());
        self::assertSame('1.000000', ExchangeRate::of(Currency::PLN, Currency::PLN, '1')->toString());
    }

    public function testOfRoundsHalfAwayFromZero(): void
    {
        self::assertSame('0.000001', ExchangeRate::of(Currency::PLN, Currency::EUR, '0.0000005')->toString());
    }

    public function testOfAcceptsNumber(): void
    {
        $rate = ExchangeRate::of(Currency::USD, Currency::EUR, new Number('0.86029394418'));

        self::assertSame('0.860294', $rate->toString());
        self::assertSame('0.860294', $rate->getRate()->value);
    }
}
```

`tests/ValueObject/MoneyTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\Exception\InvalidMoneyAmountException;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use BcMath\Number;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RoundingMode;

class MoneyTest extends TestCase
{
    #[DataProvider('validAmountProvider')]
    public function testOfFormatsToCurrencyScale(string $amount, Currency $currency, string $expected): void
    {
        $money = Money::of($amount, $currency);

        self::assertSame($expected, $money->toString());
        self::assertSame($currency, $money->getCurrency());
    }

    public static function validAmountProvider(): Generator
    {
        yield 'integer PLN' => ['100', Currency::PLN, '100.00'];
        yield 'one decimal PLN' => ['12.5', Currency::PLN, '12.50'];
        yield 'two decimals PLN' => ['12.34', Currency::PLN, '12.34'];
        yield 'negative PLN' => ['-5.10', Currency::PLN, '-5.10'];
        yield 'integer JPY' => ['1250', Currency::JPY, '1250'];
    }

    public function testOfAcceptsTrailingZerosBeyondScale(): void
    {
        self::assertSame('12.50', Money::of('12.5000', Currency::PLN)->toString());
        self::assertSame('4333', Money::of('4333.0000', Currency::JPY)->toString());
    }

    public function testOfNormalizesNegativeZeroAndLeadingZeros(): void
    {
        self::assertSame('0.00', Money::of('-0.00', Currency::PLN)->toString());
        self::assertSame('7.50', Money::of('007.5', Currency::PLN)->toString());
    }

    #[DataProvider('invalidFormatProvider')]
    public function testOfRejectsInvalidFormat(string $amount): void
    {
        $this->expectException(InvalidMoneyAmountException::class);

        Money::of($amount, Currency::PLN);
    }

    public static function invalidFormatProvider(): Generator
    {
        yield 'empty' => [''];
        yield 'exponent' => ['1e5'];
        yield 'leading space' => [' 100'];
        yield 'trailing newline' => ["100\n"];
        yield 'comma' => ['10,50'];
        yield 'plus sign' => ['+10'];
        yield 'dot only' => ['.5'];
        yield 'trailing dot' => ['5.'];
        yield 'letters' => ['abc'];
    }

    public function testOfRejectsTooManyDecimalPlaces(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for PLN.');

        Money::of('12.505', Currency::PLN);
    }

    public function testOfRejectsDecimalsForJpy(): void
    {
        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for JPY.');

        Money::of('100.5', Currency::JPY);
    }

    public function testZero(): void
    {
        self::assertSame('0.00', Money::zero(Currency::EUR)->toString());
        self::assertSame('0', Money::zero(Currency::JPY)->toString());
    }

    public function testIsValidFormat(): void
    {
        self::assertTrue(Money::isValidFormat('100.50'));
        self::assertTrue(Money::isValidFormat('-1'));
        self::assertFalse(Money::isValidFormat('1e3'));
        self::assertFalse(Money::isValidFormat("100\n"));
    }

    public function testAdd(): void
    {
        $sum = Money::of('10.25', Currency::PLN)->add(Money::of('0.75', Currency::PLN));

        self::assertSame('11.00', $sum->toString());
    }

    public function testSubtract(): void
    {
        $difference = Money::of('10.00', Currency::PLN)->subtract(Money::of('10.01', Currency::PLN));

        self::assertSame('-0.01', $difference->toString());
    }

    public function testAddIsExactWhereFloatIsNot(): void
    {
        $sum = Money::of('0.10', Currency::PLN)->add(Money::of('0.20', Currency::PLN));

        self::assertTrue($sum->equals(Money::of('0.30', Currency::PLN)));
    }

    public function testAddRejectsDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);
        $this->expectExceptionMessage('Currency mismatch: expected PLN, got EUR.');

        Money::of('1.00', Currency::PLN)->add(Money::of('1.00', Currency::EUR));
    }

    public function testSubtractRejectsDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::PLN)->subtract(Money::of('1.00', Currency::EUR));
    }

    public function testConvertToRoundsToTargetScale(): void
    {
        $rate = ExchangeRate::of(Currency::PLN, Currency::HUF, '84.745763');

        $converted = Money::of('100.00', Currency::PLN)->convertTo(Currency::HUF, $rate, RoundingMode::HalfAwayFromZero);

        self::assertSame('8474.58', $converted->toString());
        self::assertSame(Currency::HUF, $converted->getCurrency());
    }

    public function testConvertToJpyRoundsToWholeUnits(): void
    {
        $rate = ExchangeRate::of(Currency::PLN, Currency::JPY, '43.668122');

        $converted = Money::of('100.00', Currency::PLN)->convertTo(Currency::JPY, $rate, RoundingMode::HalfAwayFromZero);

        self::assertSame('4367', $converted->toString());
    }

    public function testConvertToUsesRoundingModeOnHalf(): void
    {
        $rate = ExchangeRate::of(Currency::PLN, Currency::EUR, '0.5');

        $money = Money::of('0.05', Currency::PLN); // 0.025 EUR

        self::assertSame('0.03', $money->convertTo(Currency::EUR, $rate, RoundingMode::HalfAwayFromZero)->toString());
        self::assertSame('0.02', $money->convertTo(Currency::EUR, $rate, RoundingMode::HalfEven)->toString());
    }

    public function testConvertToRejectsRateFromOtherCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::USD)->convertTo(
            Currency::EUR,
            ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            RoundingMode::HalfAwayFromZero,
        );
    }

    public function testConvertToRejectsRateToOtherCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::PLN)->convertTo(
            Currency::USD,
            ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            RoundingMode::HalfAwayFromZero,
        );
    }

    public function testPercentage(): void
    {
        $percent = new Number('1.0526315789');

        $result = Money::of('8474.58', Currency::HUF)->percentage($percent, RoundingMode::HalfAwayFromZero);

        self::assertSame('89.21', $result->toString());
        self::assertSame(Currency::HUF, $result->getCurrency());
    }

    public function testPercentageForJpy(): void
    {
        $result = Money::of('4367', Currency::JPY)->percentage(new Number('0.7692307692'), RoundingMode::HalfAwayFromZero);

        self::assertSame('34', $result->toString());
    }

    public function testIsGreaterThan(): void
    {
        $threshold = Money::of('15000', Currency::HUF);

        self::assertTrue(Money::of('15000.01', Currency::HUF)->isGreaterThan($threshold));
        self::assertFalse(Money::of('15000.00', Currency::HUF)->isGreaterThan($threshold));
        self::assertFalse(Money::of('14999.99', Currency::HUF)->isGreaterThan($threshold));
    }

    public function testIsGreaterThanRejectsDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::PLN)->isGreaterThan(Money::of('1.00', Currency::EUR));
    }

    public function testEquals(): void
    {
        self::assertTrue(Money::of('1.5', Currency::PLN)->equals(Money::of('1.50', Currency::PLN)));
        self::assertFalse(Money::of('1.50', Currency::PLN)->equals(Money::of('1.51', Currency::PLN)));
    }

    public function testEqualsRejectsDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        Money::of('1.00', Currency::PLN)->equals(Money::of('1.00', Currency::EUR));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/ValueObject`
Expected: Errors `Class "App\ValueObject\ExchangeRate" not found` / `Class "App\ValueObject\Money" not found`.

- [ ] **Step 3: Implement exceptions**

`src/Exception/InvalidMoneyAmountException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Currency;
use RuntimeException;

final class InvalidMoneyAmountException extends RuntimeException
{
    public static function invalidFormat(string $amount): self
    {
        return new self(sprintf('Invalid money amount "%s".', $amount));
    }

    public static function tooManyDecimalPlaces(Currency $currency): self
    {
        return new self(sprintf('Amount has too many decimal places for %s.', $currency->value));
    }
}
```

`src/Exception/CurrencyMismatchException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Exception;

use App\Enum\Currency;
use LogicException;

final class CurrencyMismatchException extends LogicException
{
    public function __construct(Currency $expected, Currency $actual)
    {
        parent::__construct(sprintf('Currency mismatch: expected %s, got %s.', $expected->value, $actual->value));
    }
}
```

- [ ] **Step 4: Implement `ExchangeRate`**

`src/ValueObject/ExchangeRate.php`:

```php
<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\Currency;
use BcMath\Number;

final readonly class ExchangeRate
{
    public const int SCALE = 6;

    private function __construct(
        private Currency $from,
        private Currency $to,
        private Number $rate,
    ) {
    }

    /**
     * Amount of $to currency per one unit of $from currency, rounded to SCALE decimal places.
     */
    public static function of(Currency $from, Currency $to, Number|string $rate): self
    {
        $number = $rate instanceof Number ? $rate : new Number($rate);

        return new self($from, $to, $number->round(self::SCALE));
    }

    public function getFrom(): Currency
    {
        return $this->from;
    }

    public function getTo(): Currency
    {
        return $this->to;
    }

    public function getRate(): Number
    {
        return $this->rate;
    }

    public function toString(): string
    {
        return $this->rate->value;
    }
}
```

- [ ] **Step 5: Implement `Money`**

`src/ValueObject/Money.php`:

```php
<?php

declare(strict_types=1);

namespace App\ValueObject;

use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\Exception\InvalidMoneyAmountException;
use BcMath\Number;
use RoundingMode;

final readonly class Money
{
    private const string FORMAT = '/^-?\d+(\.\d+)?$/D';
    private const int INTERMEDIATE_SCALE = 20;

    /**
     * @param Number $amount always rounded to $currency->scale()
     */
    private function __construct(
        private Number $amount,
        private Currency $currency,
    ) {
    }

    /**
     * @throws InvalidMoneyAmountException when the format is invalid or the amount is more precise than the currency allows
     */
    public static function of(string $amount, Currency $currency): self
    {
        if (!self::isValidFormat($amount)) {
            throw InvalidMoneyAmountException::invalidFormat($amount);
        }

        $fraction = explode('.', $amount)[1] ?? '';
        if ('' !== rtrim(substr($fraction, $currency->scale()), '0')) {
            throw InvalidMoneyAmountException::tooManyDecimalPlaces($currency);
        }

        return new self(new Number($amount)->round($currency->scale()), $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(new Number(0)->round($currency->scale()), $currency);
    }

    public static function isValidFormat(string $amount): bool
    {
        return 1 === preg_match(self::FORMAT, $amount);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other->currency);

        return new self($this->amount->add($other->amount), $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other->currency);

        return new self($this->amount->sub($other->amount), $this->currency);
    }

    public function convertTo(Currency $target, ExchangeRate $rate, RoundingMode $mode): self
    {
        $this->assertSameCurrency($rate->getFrom());

        if ($rate->getTo() !== $target) {
            throw new CurrencyMismatchException($target, $rate->getTo());
        }

        return new self($this->amount->mul($rate->getRate())->round($target->scale(), $mode), $target);
    }

    /**
     * @param Number $percent e.g. 1.5 for 1.5%
     */
    public function percentage(Number $percent, RoundingMode $mode): self
    {
        $result = $this->amount
            ->mul($percent)
            ->div(100, self::INTERMEDIATE_SCALE)
            ->round($this->currency->scale(), $mode);

        return new self($result, $this->currency);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other->currency);

        return $this->amount->compare($other->amount) > 0;
    }

    public function equals(self $other): bool
    {
        $this->assertSameCurrency($other->currency);

        return 0 === $this->amount->compare($other->amount);
    }

    public function getCurrency(): Currency
    {
        return $this->currency;
    }

    public function toString(): string
    {
        return $this->amount->value;
    }

    private function assertSameCurrency(Currency $currency): void
    {
        if ($currency !== $this->currency) {
            throw new CurrencyMismatchException($this->currency, $currency);
        }
    }
}
```

Uwaga: `Number::round($precision)` zawsze zwraca liczbę o skali dokładnie `$precision` (`"10"` → `"10.00"`, `"-0.004"` → `"0.00"`), dlatego `toString()` może zwracać `->value` bez dodatkowego formatowania. `add`/`sub` dwóch liczb o skali N dają skalę N.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php bin/phpunit tests/ValueObject`
Expected: `OK` (wszystkie testy `ExchangeRateTest` i `MoneyTest`).

- [ ] **Step 7: Commit**

```bash
git add src/Exception/InvalidMoneyAmountException.php src/Exception/CurrencyMismatchException.php src/ValueObject tests/ValueObject
git commit -m "feat: add Money and ExchangeRate value objects

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: `ExchangeRateService` i `SpreadService` na `Number`/`Money`

**Files:**
- Modify: `src/Service/ExchangeRateService.php`, `src/Service/SpreadService.php`
- Test: `tests/Service/ExchangeRateServiceTest.php`, `tests/Service/SpreadServiceTest.php`

**Interfaces:**
- Consumes: `ExchangeRate::of()`, `Money::zero()`, `Money::percentage()`.
- Produces:
  - `ExchangeRateService::getExchangeRate(Currency $currency): Number` (PLN za 1 jednostkę waluty)
  - `ExchangeRateService::getExchangeRateBetween(Currency $from, Currency $to): ExchangeRate`
  - `SpreadService::calculateSpread(Money $price, Currency $fromCurrency, Currency $toCurrency): Money` (w walucie `$price`)

- [ ] **Step 1: Rewrite the tests (failing)**

`tests/Service/ExchangeRateServiceTest.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Service\ExchangeRateService;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExchangeRateServiceTest extends TestCase
{
    private ExchangeRateService $exchangeRateService;

    protected function setUp(): void
    {
        $this->exchangeRateService = new ExchangeRateService();
    }

    #[DataProvider('exchangeRateDataProvider')]
    public function testGetExchangeRate(
        Currency $currency,
        string $expectedResult,
    ): void {
        $result = $this->exchangeRateService->getExchangeRate($currency);

        self::assertSame($expectedResult, $result->value);
    }

    #[DataProvider('exchangeRateBetweenDataProvider')]
    public function testGetExchangeRateBetween(
        Currency $from,
        Currency $to,
        string $expectedResult,
    ): void {
        $result = $this->exchangeRateService->getExchangeRateBetween($from, $to);

        self::assertSame($expectedResult, $result->toString());
        self::assertSame($from, $result->getFrom());
        self::assertSame($to, $result->getTo());
    }

    public static function exchangeRateDataProvider(): Generator
    {
        yield 'PLN' => ['currency' => Currency::PLN, 'expectedResult' => '1'];
        yield 'EUR' => ['currency' => Currency::EUR, 'expectedResult' => '4.2389'];
        yield 'USD' => ['currency' => Currency::USD, 'expectedResult' => '3.6467'];
        yield 'GBP' => ['currency' => Currency::GBP, 'expectedResult' => '4.881'];
        yield 'JPY' => ['currency' => Currency::JPY, 'expectedResult' => '0.0229'];
        yield 'CHF' => ['currency' => Currency::CHF, 'expectedResult' => '4.6347'];
        yield 'HUF' => ['currency' => Currency::HUF, 'expectedResult' => '0.0118'];
    }

    public static function exchangeRateBetweenDataProvider(): Generator
    {
        yield 'PLN to PLN' => [
            'from' => Currency::PLN,
            'to' => Currency::PLN,
            'expectedResult' => '1.000000',
        ];
        yield 'EUR to PLN' => [
            'from' => Currency::EUR,
            'to' => Currency::PLN,
            'expectedResult' => '4.238900',
        ];
        yield 'PLN to EUR' => [
            'from' => Currency::PLN,
            'to' => Currency::EUR,
            'expectedResult' => '0.235910',
        ];
        yield 'USD to EUR' => [
            'from' => Currency::USD,
            'to' => Currency::EUR,
            'expectedResult' => '0.860294',
        ];
        yield 'GBP to CHF' => [
            'from' => Currency::GBP,
            'to' => Currency::CHF,
            'expectedResult' => '1.053143',
        ];
        yield 'PLN to HUF' => [
            'from' => Currency::PLN,
            'to' => Currency::HUF,
            'expectedResult' => '84.745763',
        ];
    }
}
```

`tests/Service/SpreadServiceTest.php` — zastąp całą zawartość (oczekiwane wartości policzone na `BcMath\Number`; zmiana względem floatów tylko dla JPY, gdzie skala to 0):

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Enum\Currency;
use App\Service\SpreadService;
use App\ValueObject\Money;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SpreadServiceTest extends TestCase
{
    private SpreadService $spreadService;

    protected function setUp(): void
    {
        $this->spreadService = new SpreadService();
    }

    #[DataProvider('calculateSpreadDataProvider')]
    public function testCalculateSpread(
        string $price,
        Currency $fromCurrency,
        Currency $toCurrency,
        string $expectedResult,
    ): void {
        $result = $this->spreadService->calculateSpread(Money::of($price, $toCurrency), $fromCurrency, $toCurrency);

        self::assertSame($expectedResult, $result->toString());
        self::assertSame($toCurrency, $result->getCurrency());
    }

    public static function calculateSpreadDataProvider(): Generator
    {
        yield 'USD to USD (same currency, no conversion)' => [
            'price' => '100.00',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::USD,
            'expectedResult' => '0.00',
        ];
        yield 'PLN to PLN (same currency, no conversion)' => [
            'price' => '100.00',
            'fromCurrency' => Currency::PLN,
            'toCurrency' => Currency::PLN,
            'expectedResult' => '0.00',
        ];
        yield 'HUF to HUF (same currency, no conversion)' => [
            'price' => '100.00',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::HUF,
            'expectedResult' => '0.00',
        ];
        yield 'USD to EUR' => [
            'price' => '100.00',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '0.51',
        ];
        yield 'GBP to CHF' => [
            'price' => '100.00',
            'fromCurrency' => Currency::GBP,
            'toCurrency' => Currency::CHF,
            'expectedResult' => '0.61',
        ];
        yield 'HUF to JPY (rounded to whole yen)' => [
            'price' => '100',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::JPY,
            'expectedResult' => '1',
        ];
        yield 'HUF to JPY with higher price' => [
            'price' => '10000',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::JPY,
            'expectedResult' => '87',
        ];
        yield 'HUF to PLN' => [
            'price' => '50.00',
            'fromCurrency' => Currency::HUF,
            'toCurrency' => Currency::PLN,
            'expectedResult' => '0.53',
        ];
        yield 'USD to EUR with higher price' => [
            'price' => '200.00',
            'fromCurrency' => Currency::USD,
            'toCurrency' => Currency::EUR,
            'expectedResult' => '1.03',
        ];
        yield 'PLN to HUF (transfer reference case)' => [
            'price' => '8474.58',
            'fromCurrency' => Currency::PLN,
            'toCurrency' => Currency::HUF,
            'expectedResult' => '89.21',
        ];
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Service/ExchangeRateServiceTest.php tests/Service/SpreadServiceTest.php`
Expected: FAIL (`Attempt to read property "value" on float`, `TypeError` — `calculateSpread()` expects float).

- [ ] **Step 3: Implement**

`src/Service/ExchangeRateService.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\ValueObject\ExchangeRate;
use BcMath\Number;

class ExchangeRateService
{
    private const int DIVISION_SCALE = 10;

    /**
     * Price of one unit of $currency in PLN.
     */
    public function getExchangeRate(Currency $currency): Number
    {
        return new Number(match ($currency) {
            Currency::PLN => '1',
            Currency::EUR => '4.2389',
            Currency::USD => '3.6467',
            Currency::GBP => '4.881',
            Currency::JPY => '0.0229',
            Currency::CHF => '4.6347',
            Currency::HUF => '0.0118',
        });
    }

    public function getExchangeRateBetween(Currency $from, Currency $to): ExchangeRate
    {
        $rate = $this->getExchangeRate($from)->div($this->getExchangeRate($to), self::DIVISION_SCALE);

        return ExchangeRate::of($from, $to, $rate);
    }
}
```

`src/Service/SpreadService.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Currency;
use App\ValueObject\Money;
use BcMath\Number;
use RoundingMode;

class SpreadService
{
    private const array LIQUIDITY_SCORE = [
        Currency::USD->value => '1.00',
        Currency::EUR->value => '0.95',
        Currency::GBP->value => '0.85',
        Currency::CHF->value => '0.80',
        Currency::JPY->value => '0.75',
        Currency::PLN->value => '0.55',
        Currency::HUF->value => '0.40',
    ];

    private const string BASE_SPREAD_PERCENT = '0.5';
    private const int PERCENT_SCALE = 10;

    public function calculateSpread(
        Money $price,
        Currency $fromCurrency,
        Currency $toCurrency,
    ): Money {
        if ($fromCurrency === $toCurrency) {
            return Money::zero($price->getCurrency());
        }

        $fromLiquidity = new Number(self::LIQUIDITY_SCORE[$fromCurrency->value]);
        $toLiquidity = self::LIQUIDITY_SCORE[$toCurrency->value];

        $pairLiquidity = $fromLiquidity->add($toLiquidity)->div(2, self::PERCENT_SCALE);

        $spreadPercent = new Number(self::BASE_SPREAD_PERCENT)->div($pairLiquidity, self::PERCENT_SCALE);

        return $price->percentage($spreadPercent, RoundingMode::HalfAwayFromZero);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php bin/phpunit tests/Service/ExchangeRateServiceTest.php tests/Service/SpreadServiceTest.php`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Service/ExchangeRateService.php src/Service/SpreadService.php tests/Service/ExchangeRateServiceTest.php tests/Service/SpreadServiceTest.php
git commit -m "refactor: compute exchange rates and spread with bcmath

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: `Wallet` i `CompanyWallet` na `Money` (encje, DTO, repozytoria, komenda)

**Files:**
- Modify: `src/Entity/Wallet.php`, `src/Entity/CompanyWallet.php`, `src/Dto/WalletResponse.php`, `src/Repository/WalletRepository.php`, `src/Repository/CompanyWalletRepository.php`, `src/Repository/CompanyWalletRepositoryInterface.php`, `src/Command/ShowCompanyWalletCommand.php`
- Test: `tests/Entity/WalletTest.php`, `tests/Entity/CompanyWalletTest.php`, `tests/Dto/WalletResponseTest.php`

**Interfaces:**
- Consumes: `Money`, `CurrencyMismatchException`.
- Produces:
  - `Wallet::__construct(?int $id, int $userId, Currency $currency, Money $balance, bool $isBlocked, ?DateTimeImmutable $lastActivityAt, DateTimeImmutable $createdAt)` — rzuca `CurrencyMismatchException` gdy waluta `$balance` ≠ `$currency`.
  - `Wallet::getBalance(): Money`, `Wallet::setBalance(Money $balance): void` (ten sam guard).
  - `CompanyWallet::__construct(?int $id, Currency $currency, Money $balance, DateTimeImmutable $createdAt, DateTimeImmutable $updatedAt)`, `getBalance(): Money`, `setBalance(Money): void` (guard).
  - `CompanyWalletRepositoryInterface::addToBalance(Money $amount): void` (waluta z `$amount`).
  - `WalletResponse` → `'balance' => string`.

- [ ] **Step 1: Update tests (failing)**

`tests/Entity/WalletTest.php` — dodaj importy i zmień dwa testy, dopisz dwa nowe:

```php
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
```

```php
    public function testGetters(): void
    {
        $wallet = Wallet::create(userId: 5, currency: Currency::EUR);

        $this->assertNull($wallet->getId());
        $this->assertSame(5, $wallet->getUserId());
        $this->assertSame(Currency::EUR, $wallet->getCurrency());
        $this->assertSame('0.00', $wallet->getBalance()->toString());
        $this->assertSame(Currency::EUR, $wallet->getBalance()->getCurrency());
        $this->assertFalse($wallet->isBlocked());
        $this->assertNull($wallet->getLastActivityAt());
    }

    public function testSetBalance(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);
        $wallet->setBalance(Money::of('150.50', Currency::PLN));

        $this->assertSame('150.50', $wallet->getBalance()->toString());
    }

    public function testSetBalanceRejectsDifferentCurrency(): void
    {
        $wallet = Wallet::create(userId: 1, currency: Currency::PLN);

        $this->expectException(CurrencyMismatchException::class);

        $wallet->setBalance(Money::of('1.00', Currency::EUR));
    }

    public function testConstructorRejectsBalanceInDifferentCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        new Wallet(
            id: 1,
            userId: 1,
            currency: Currency::PLN,
            balance: Money::of('1.00', Currency::EUR),
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
```

`tests/Entity/CompanyWalletTest.php` — dodaj `use App\Exception\CurrencyMismatchException;` i `use App\ValueObject\Money;`, zamień trzy testy, dopisz jeden:

```php
    public function testCreateInitialValues(): void
    {
        $wallet = CompanyWallet::create(Currency::EUR);

        $this->assertNull($wallet->getId());
        $this->assertSame(Currency::EUR, $wallet->getCurrency());
        $this->assertSame('0.00', $wallet->getBalance()->toString());
    }
```

```php
    public function testSetBalance(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);
        $wallet->setBalance(Money::of('250.75', Currency::PLN));

        $this->assertSame('250.75', $wallet->getBalance()->toString());
    }

    public function testSetBalanceToZero(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);
        $wallet->setBalance(Money::of('100.00', Currency::PLN));
        $wallet->setBalance(Money::zero(Currency::PLN));

        $this->assertSame('0.00', $wallet->getBalance()->toString());
    }

    public function testSetBalanceRejectsDifferentCurrency(): void
    {
        $wallet = CompanyWallet::create(Currency::PLN);

        $this->expectException(CurrencyMismatchException::class);

        $wallet->setBalance(Money::of('1.00', Currency::EUR));
    }
```

`tests/Dto/WalletResponseTest.php` — w `testJsonSerializeWithoutLastActivityAt` zmień asercję salda i dopisz test (dodaj `use App\ValueObject\Money;`):

```php
        self::assertSame('0.00', $data['balance']);
```

```php
    public function testJsonSerializeBalanceAsStringInCurrencyScale(): void
    {
        $wallet = Wallet::create(1, Currency::JPY);
        $wallet->setBalance(Money::of('1250', Currency::JPY));

        $data = new WalletResponse($wallet)->jsonSerialize();

        self::assertSame('1250', $data['balance']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Entity/WalletTest.php tests/Entity/CompanyWalletTest.php tests/Dto/WalletResponseTest.php`
Expected: FAIL (`Call to a member function toString() on float`, `TypeError` w `setBalance`).

- [ ] **Step 3: Implement entities**

`src/Entity/Wallet.php` — zmiany:

```php
use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\Money;
use DateTimeImmutable;

class Wallet
{
    public function __construct(
        private ?int $id,
        private readonly int $userId,
        private readonly Currency $currency,
        private Money $balance,
        private bool $isBlocked,
        private ?DateTimeImmutable $lastActivityAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
        $this->assertBalanceCurrency($balance);
    }

    public static function create(int $userId, Currency $currency): self
    {
        return new self(
            id: null,
            userId: $userId,
            currency: $currency,
            balance: Money::zero($currency),
            isBlocked: false,
            lastActivityAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
```

```php
    public function getBalance(): Money
    {
        return $this->balance;
    }
```

```php
    public function setBalance(Money $balance): void
    {
        $this->assertBalanceCurrency($balance);
        $this->balance = $balance;
    }
```

i na końcu klasy:

```php
    private function assertBalanceCurrency(Money $balance): void
    {
        if ($balance->getCurrency() !== $this->currency) {
            throw new CurrencyMismatchException($this->currency, $balance->getCurrency());
        }
    }
```

`src/Entity/CompanyWallet.php` — analogicznie:

```php
use App\Enum\Currency;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\Money;
use DateTimeImmutable;

class CompanyWallet
{
    public function __construct(
        private ?int $id,
        private readonly Currency $currency,
        private Money $balance,
        private readonly DateTimeImmutable $createdAt,
        private DateTimeImmutable $updatedAt,
    ) {
        $this->assertBalanceCurrency($balance);
    }

    public static function create(Currency $currency): self
    {
        $now = new DateTimeImmutable();

        return new self(
            id: null,
            currency: $currency,
            balance: Money::zero($currency),
            createdAt: $now,
            updatedAt: $now,
        );
    }
```

```php
    public function getBalance(): Money
    {
        return $this->balance;
    }
```

```php
    public function setBalance(Money $balance): void
    {
        $this->assertBalanceCurrency($balance);
        $this->balance = $balance;
    }

    private function assertBalanceCurrency(Money $balance): void
    {
        if ($balance->getCurrency() !== $this->currency) {
            throw new CurrencyMismatchException($this->currency, $balance->getCurrency());
        }
    }
```

- [ ] **Step 4: Implement DTO, repositories, command**

`src/Dto/WalletResponse.php`:

```php
            'balance' => $this->wallet->getBalance()->toString(),
```

`src/Repository/WalletRepository.php` — dodaj `use App\ValueObject\Money;`, zmień `buildEntity()`:

```php
    private function buildEntity(array $row): Wallet
    {
        $currency = Currency::from($row['currency']);

        return new Wallet(
            id: (int) $row['id'],
            userId: (int) $row['user_id'],
            currency: $currency,
            balance: Money::of((string) $row['balance'], $currency),
            isBlocked: (bool) $row['is_blocked'],
            lastActivityAt: null !== $row['last_activity_at'] ? new DateTimeImmutable($row['last_activity_at']) : null,
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }
```

oraz w `insert()` i `update()`:

```php
                'balance' => $wallet->getBalance()->toString(),
```

`src/Repository/CompanyWalletRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CompanyWallet;
use App\Enum\Currency;
use App\ValueObject\Money;

interface CompanyWalletRepositoryInterface
{
    public function findByCurrency(Currency $currency): ?CompanyWallet;

    /** @return CompanyWallet[] */
    public function findAll(): array;

    public function addToBalance(Money $amount): void;
}
```

`src/Repository/CompanyWalletRepository.php` — dodaj `use App\ValueObject\Money;`; `addToBalance`:

```php
    /**
     * @throws Exception
     */
    public function addToBalance(Money $amount): void
    {
        $currency = $amount->getCurrency();
        $now = new DateTimeImmutable()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        $existing = $this->findByCurrency($currency);

        $qb = $this->connection->createQueryBuilder();
        if (null === $existing) {
            $qb
                ->insert(self::TABLE_NAME)
                ->values([
                    'currency' => ':currency',
                    'balance' => ':balance',
                    'created_at' => ':created_at',
                    'updated_at' => ':updated_at',
                ]);

            $this->connection->executeStatement($qb->getSQL(), [
                'currency' => $currency->value,
                'balance' => $amount->toString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $qb
                ->update(self::TABLE_NAME)
                ->set('balance', 'balance + :amount')
                ->set('updated_at', ':updated_at')
                ->where('currency = :currency');

            $this->connection->executeStatement($qb->getSQL(), [
                'amount' => $amount->toString(),
                'updated_at' => $now,
                'currency' => $currency->value,
            ]);
        }
    }
```

i `buildEntity()`:

```php
    private function buildEntity(array $row): CompanyWallet
    {
        $currency = Currency::from($row['currency']);

        return new CompanyWallet(
            id: (int) $row['id'],
            currency: $currency,
            balance: Money::of((string) $row['balance'], $currency),
            createdAt: new DateTimeImmutable($row['created_at']),
            updatedAt: new DateTimeImmutable($row['updated_at']),
        );
    }
```

`src/Command/ShowCompanyWalletCommand.php` — dodaj `use App\Entity\CompanyWallet;`, zmień mapowanie wierszy:

```php
        $rows = array_map(
            static fn (CompanyWallet $wallet) => [$wallet->getCurrency()->value, $wallet->getBalance()->toString()],
            $wallets,
        );
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php bin/phpunit tests/Entity/WalletTest.php tests/Entity/CompanyWalletTest.php tests/Dto/WalletResponseTest.php && php -l src/Repository/WalletRepository.php && php -l src/Repository/CompanyWalletRepository.php && php -l src/Command/ShowCompanyWalletCommand.php`
Expected: `OK`, `No syntax errors detected` ×3.

- [ ] **Step 6: Commit**

```bash
git add src/Entity/Wallet.php src/Entity/CompanyWallet.php src/Dto/WalletResponse.php src/Repository/WalletRepository.php src/Repository/CompanyWalletRepository.php src/Repository/CompanyWalletRepositoryInterface.php src/Command/ShowCompanyWalletCommand.php tests/Entity/WalletTest.php tests/Entity/CompanyWalletTest.php tests/Dto/WalletResponseTest.php
git commit -m "refactor: store wallet balances as Money

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: `Transaction` na `Money`/`ExchangeRate` (encja, DTO, repozytorium, komenda)

**Files:**
- Modify: `src/Entity/Transaction.php`, `src/Dto/TransactionResponse.php`, `src/Repository/TransactionRepository.php`, `src/Command/ProcessTransactionsCommand.php`
- Test: `tests/Entity/TransactionTest.php`, `tests/Dto/TransactionResponseTest.php`

**Interfaces:**
- Consumes: `Money`, `ExchangeRate`, `CurrencyMismatchException`.
- Produces:
  - `Transaction::__construct(?int $id, int $fromWalletId, int $toWalletId, Money $fromAmount, Money $toAmount, Money $spread, ExchangeRate $exchangeRate, TransactionStatus $status, bool $requiresAntiFraudCheck, ?DateTimeImmutable $antiFraudCheckedAt, DateTimeImmutable $createdAt)` — **bez** `fromCurrency`/`toCurrency`; rzuca `CurrencyMismatchException`, gdy `exchangeRate->getFrom() ≠ fromAmount` waluta, `exchangeRate->getTo() ≠ toAmount` waluta lub `spread` waluta ≠ `toAmount` waluta.
  - `Transaction::create(int $fromWalletId, int $toWalletId, Money $fromAmount, Money $toAmount, Money $spread, ExchangeRate $exchangeRate, bool $requiresAntiFraudCheck): self`
  - `getFromAmount(): Money`, `getToAmount(): Money`, `getSpread(): Money`, `getExchangeRate(): ExchangeRate`, `getFromCurrency(): Currency` (z `fromAmount`), `getToCurrency(): Currency` (z `toAmount`).
  - `TransactionResponse`: `fromAmount`, `toAmount`, `spread`, `exchangeRate` jako stringi.

- [ ] **Step 1: Update tests (failing)**

`tests/Entity/TransactionTest.php` — importy:

```php
use App\Entity\Transaction;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
```

`testGetters` — asercje kwot:

```php
        $this->assertSame('100.00', $transaction->getFromAmount()->toString());
        $this->assertSame('90.00', $transaction->getToAmount()->toString());
        $this->assertSame(Currency::PLN, $transaction->getFromCurrency());
        $this->assertSame(Currency::EUR, $transaction->getToCurrency());
        $this->assertSame('0.02', $transaction->getSpread()->toString());
        $this->assertSame('0.220000', $transaction->getExchangeRate()->toString());
```

nowe testy (Review Focus #5):

```php
    public function testConstructorRejectsSpreadInSourceCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $this->makeTransactionWith(spread: Money::of('0.02', Currency::PLN));
    }

    public function testConstructorRejectsExchangeRateFromOtherCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $this->makeTransactionWith(exchangeRate: ExchangeRate::of(Currency::USD, Currency::EUR, '0.22'));
    }

    public function testConstructorRejectsExchangeRateToOtherCurrency(): void
    {
        $this->expectException(CurrencyMismatchException::class);

        $this->makeTransactionWith(exchangeRate: ExchangeRate::of(Currency::PLN, Currency::USD, '0.22'));
    }
```

helpery na końcu klasy (zastępują obecny `makeTransaction`):

```php
    private function makeTransaction(bool $requiresAntiFraudCheck = false): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('90.00', Currency::EUR),
            spread: Money::of('0.02', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.22'),
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
        );
    }

    private function makeTransactionWith(?Money $spread = null, ?ExchangeRate $exchangeRate = null): Transaction
    {
        return new Transaction(
            id: 1,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('90.00', Currency::EUR),
            spread: $spread ?? Money::of('0.02', Currency::EUR),
            exchangeRate: $exchangeRate ?? ExchangeRate::of(Currency::PLN, Currency::EUR, '0.22'),
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }
```

`tests/Dto/TransactionResponseTest.php` — dodaj `use App\ValueObject\ExchangeRate;` i `use App\ValueObject\Money;`; `makeTransaction()`:

```php
        return new Transaction(
            id: 7,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('25.12', Currency::EUR),
            spread: Money::of('0.13', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            status: $status,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable('2026-01-15T10:00:00+00:00'),
        );
```

asercje w `testJsonSerializeReturnsAllFields`:

```php
        self::assertSame('100.00', $data['fromAmount']);
        self::assertSame('25.12', $data['toAmount']);
        self::assertSame('PLN', $data['fromCurrency']);
        self::assertSame('EUR', $data['toCurrency']);
        self::assertSame('0.13', $data['spread']);
        self::assertSame('0.250000', $data['exchangeRate']);
```

`testJsonSerializeWithNullId` — transakcja:

```php
        $transaction = new Transaction(
            id: null,
            fromWalletId: 3,
            toWalletId: 4,
            fromAmount: Money::of('50.00', Currency::USD),
            toAmount: Money::of('50.00', Currency::USD),
            spread: Money::zero(Currency::USD),
            exchangeRate: ExchangeRate::of(Currency::USD, Currency::USD, '1'),
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Entity/TransactionTest.php tests/Dto/TransactionResponseTest.php`
Expected: FAIL / Error (`Unknown named parameter $spread` lub `TypeError` dla `fromAmount`).

- [ ] **Step 3: Implement entity**

`src/Entity/Transaction.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\CurrencyMismatchException;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use DateTimeImmutable;

class Transaction
{
    public function __construct(
        private ?int $id,
        private readonly int $fromWalletId,
        private readonly int $toWalletId,
        private readonly Money $fromAmount,
        private readonly Money $toAmount,
        private readonly Money $spread,
        private readonly ExchangeRate $exchangeRate,
        private TransactionStatus $status,
        private readonly bool $requiresAntiFraudCheck,
        private ?DateTimeImmutable $antiFraudCheckedAt,
        private readonly DateTimeImmutable $createdAt,
    ) {
        if ($exchangeRate->getFrom() !== $fromAmount->getCurrency()) {
            throw new CurrencyMismatchException($fromAmount->getCurrency(), $exchangeRate->getFrom());
        }

        if ($exchangeRate->getTo() !== $toAmount->getCurrency()) {
            throw new CurrencyMismatchException($toAmount->getCurrency(), $exchangeRate->getTo());
        }

        if ($spread->getCurrency() !== $toAmount->getCurrency()) {
            throw new CurrencyMismatchException($toAmount->getCurrency(), $spread->getCurrency());
        }
    }

    public static function create(
        int $fromWalletId,
        int $toWalletId,
        Money $fromAmount,
        Money $toAmount,
        Money $spread,
        ExchangeRate $exchangeRate,
        bool $requiresAntiFraudCheck,
    ): self {
        return new self(
            id: null,
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromAmount,
            toAmount: $toAmount,
            spread: $spread,
            exchangeRate: $exchangeRate,
            status: $requiresAntiFraudCheck
                ? TransactionStatus::FRAUD_REVIEW
                : TransactionStatus::PENDING,
            requiresAntiFraudCheck: $requiresAntiFraudCheck,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFromWalletId(): int
    {
        return $this->fromWalletId;
    }

    public function getToWalletId(): int
    {
        return $this->toWalletId;
    }

    public function getFromAmount(): Money
    {
        return $this->fromAmount;
    }

    public function getToAmount(): Money
    {
        return $this->toAmount;
    }

    public function getFromCurrency(): Currency
    {
        return $this->fromAmount->getCurrency();
    }

    public function getToCurrency(): Currency
    {
        return $this->toAmount->getCurrency();
    }

    public function getSpread(): Money
    {
        return $this->spread;
    }

    public function getExchangeRate(): ExchangeRate
    {
        return $this->exchangeRate;
    }

    public function getStatus(): TransactionStatus
    {
        return $this->status;
    }

    public function requiresAntiFraudCheck(): bool
    {
        return $this->requiresAntiFraudCheck;
    }

    public function getAntiFraudCheckedAt(): ?DateTimeImmutable
    {
        return $this->antiFraudCheckedAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setStatus(TransactionStatus $status): void
    {
        $this->status = $status;
    }

    public function setAntiFraudCheckedAt(?DateTimeImmutable $antiFraudCheckedAt): void
    {
        $this->antiFraudCheckedAt = $antiFraudCheckedAt;
    }
}
```

- [ ] **Step 4: Implement DTO, repository, command**

`src/Dto/TransactionResponse.php`:

```php
            'fromAmount' => $this->transaction->getFromAmount()->toString(),
            'toAmount' => $this->transaction->getToAmount()->toString(),
            'fromCurrency' => $this->transaction->getFromCurrency()->value,
            'toCurrency' => $this->transaction->getToCurrency()->value,
            'spread' => $this->transaction->getSpread()->toString(),
            'exchangeRate' => $this->transaction->getExchangeRate()->toString(),
```

`src/Repository/TransactionRepository.php` — dodaj `use App\ValueObject\ExchangeRate;` i `use App\ValueObject\Money;`; `buildEntity()`:

```php
    private function buildEntity(array $row): Transaction
    {
        $fromCurrency = Currency::from($row['from_currency']);
        $toCurrency = Currency::from($row['to_currency']);

        return new Transaction(
            id: (int) $row['id'],
            fromWalletId: (int) $row['from_wallet_id'],
            toWalletId: (int) $row['to_wallet_id'],
            fromAmount: Money::of((string) $row['from_amount'], $fromCurrency),
            toAmount: Money::of((string) $row['to_amount'], $toCurrency),
            spread: Money::of((string) $row['spread'], $toCurrency),
            exchangeRate: ExchangeRate::of($fromCurrency, $toCurrency, (string) $row['exchange_rate']),
            status: TransactionStatus::from($row['status']),
            requiresAntiFraudCheck: (bool) $row['requires_anti_fraud_check'],
            antiFraudCheckedAt: null !== $row['anti_fraud_checked_at']
                ? new DateTimeImmutable($row['anti_fraud_checked_at'])
                : null,
            createdAt: new DateTimeImmutable($row['created_at']),
        );
    }
```

w `insert()` parametry:

```php
                'from_amount' => $transaction->getFromAmount()->toString(),
                'to_amount' => $transaction->getToAmount()->toString(),
                'from_currency' => $transaction->getFromCurrency()->value,
                'to_currency' => $transaction->getToCurrency()->value,
                'spread' => $transaction->getSpread()->toString(),
                'exchange_rate' => $transaction->getExchangeRate()->toString(),
```

`src/Command/ProcessTransactionsCommand.php` — w `definitionList` (obiekty nie są `Stringable`, bez tej zmiany `sprintf` rzuci `Error`):

```php
                ['Amount' => sprintf('%s %s → %s %s', $transaction->getFromAmount()->toString(), $transaction->getFromCurrency()->value, $transaction->getToAmount()->toString(), $transaction->getToCurrency()->value)],
                ['Exchange rate' => $transaction->getExchangeRate()->toString()],
                ['Spread' => $transaction->getSpread()->toString()],
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php bin/phpunit tests/Entity/TransactionTest.php tests/Dto/TransactionResponseTest.php && php -l src/Repository/TransactionRepository.php && php -l src/Command/ProcessTransactionsCommand.php`
Expected: `OK`, `No syntax errors detected` ×2.

- [ ] **Step 6: Commit**

```bash
git add src/Entity/Transaction.php src/Dto/TransactionResponse.php src/Repository/TransactionRepository.php src/Command/ProcessTransactionsCommand.php tests/Entity/TransactionTest.php tests/Dto/TransactionResponseTest.php
git commit -m "refactor: store transaction amounts as Money and rate as ExchangeRate

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Serwisy `TransferService`, `DepositService`, `TransactionProcessorService`

**Files:**
- Modify: `src/Service/TransferService.php`, `src/Service/DepositService.php`, `src/Service/TransactionProcessorService.php`
- Test: `tests/Service/TransferServiceTest.php` (pełne przepisanie — zastępuje niezacommitowany wariant `KernelTestCase`), `tests/Service/DepositServiceTest.php`, `tests/Service/TransactionProcessorServiceTest.php` (pełne przepisanie z wersji HEAD — niezacommitowana zmiana `self::once()` → `$this::once()` znika)

**Interfaces:**
- Consumes: wszystko z Tasków 2–5.
- Produces:
  - `TransferService::transfer(int $userId, int $fromWalletId, int $toWalletId, string $fromAmount): Transaction` — sygnatura bez zmian; rzuca `InvalidMoneyAmountException` (po sprawdzeniu portfeli).
  - `TransferService::ANTI_FRAUD_THRESHOLD = '15000'` (w walucie docelowej — semantyka bez zmian).
  - `DepositService::MAX_AMOUNT = '10000'` (`string`); `deposit(int $userId, int $walletId, string $amount): Wallet` rzuca `InvalidMoneyAmountException`.

- [ ] **Step 1: Rewrite the tests (failing)**

`tests/Service/TransferServiceTest.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Transaction;
use App\Entity\Wallet;
use App\Enum\Currency;
use App\Enum\TransactionStatus;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\Service\ExchangeRateService;
use App\Service\SpreadService;
use App\Service\TransferService;
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
    private TransferService $transferService;

    protected function setUp(): void
    {
        $this->walletRepository = $this->createMock(WalletRepositoryInterface::class);
        $this->transactionRepository = $this->createMock(TransactionRepositoryInterface::class);
        $this->exchangeRateService = $this->createMock(ExchangeRateService::class);
        $this->spreadService = $this->createMock(SpreadService::class);

        $this->transferService = new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            $this->exchangeRateService,
            $this->spreadService,
        );
    }

    public function testTransferSuccessfully(): void
    {
        $userId = 1;
        $fromWallet = $this->createMock(Wallet::class);
        $fromWallet
            ->method('getCurrency')
            ->willReturn(Currency::PLN);
        $fromWallet
            ->expects($this->atLeastOnce())
            ->method('getBalance')
            ->willReturnOnConsecutiveCalls(
                Money::of('5000.00', Currency::PLN),
                Money::of('4000.00', Currency::PLN),
            );
        $fromWallet
            ->method('getUserId')
            ->willReturn($userId);
        $fromWallet
            ->expects($this->never())
            ->method('setBalance');
        $toWallet = $this->createMock(Wallet::class);
        $toWallet
            ->method('getCurrency')
            ->willReturn(Currency::EUR);
        $toWallet
            ->expects($this->atLeastOnce())
            ->method('getBalance')
            ->willReturnOnConsecutiveCalls(
                Money::of('100.00', Currency::EUR),
                Money::of('349.00', Currency::EUR),
            );
        $toWallet
            ->method('getUserId')
            ->willReturn($userId);
        $toWallet
            ->expects($this->never())
            ->method('setBalance');

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
            ->expects(self::exactly(2))
            ->method('save')
            ->with($this->isInstanceOf(Wallet::class));

        $this->transactionRepository
            ->expects(self::once())
            ->method('save')
            ->with($this->isInstanceOf(Transaction::class));

        $transaction = $this->transferService->transfer($userId, 1, 2, '1000.00');

        self::assertSame('4000.00', $fromWallet->getBalance()->toString());
        self::assertSame('349.00', $toWallet->getBalance()->toString());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
        self::assertFalse($transaction->requiresAntiFraudCheck());
        self::assertSame('1000.00', $transaction->getFromAmount()->toString());
        self::assertSame('249.00', $transaction->getToAmount()->toString());
        self::assertSame('0.250000', $transaction->getExchangeRate()->toString());
        self::assertSame('1.00', $transaction->getSpread()->toString());
        self::assertSame(Currency::PLN, $transaction->getFromCurrency());
        self::assertSame(Currency::EUR, $transaction->getToCurrency());
    }

    #[DataProvider('referenceTransferProvider')]
    public function testTransferCalculatesAmountsWithRealRatesAndSpread(
        Currency $toCurrency,
        string $expectedRate,
        string $expectedSpread,
        string $expectedToAmount,
    ): void {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $fromWallet->setBalance(Money::of('500.00', Currency::PLN));
        $toWallet = Wallet::create(1, $toCurrency);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $transaction = $this->makeServiceWithRealRates()->transfer(1, 1, 2, '100.00');

        self::assertSame('100.00', $transaction->getFromAmount()->toString());
        self::assertSame($expectedRate, $transaction->getExchangeRate()->toString());
        self::assertSame($expectedSpread, $transaction->getSpread()->toString());
        self::assertSame($expectedToAmount, $transaction->getToAmount()->toString());
        self::assertSame($toCurrency, $transaction->getToCurrency());
        self::assertSame('400.00', $fromWallet->getBalance()->toString());
        self::assertSame($expectedToAmount, $toWallet->getBalance()->toString());
        self::assertSame(TransactionStatus::PENDING, $transaction->getStatus());
    }

    public static function referenceTransferProvider(): Generator
    {
        // 100 PLN × rate → gross (rounded to target scale) − spread = net
        yield 'PLN to HUF' => [Currency::HUF, '84.745763', '89.21', '8385.37'];
        yield 'PLN to JPY' => [Currency::JPY, '43.668122', '34', '4333'];
        yield 'PLN to EUR' => [Currency::EUR, '0.235910', '0.16', '23.43'];
    }

    public function testTransferFlagsAntiFraudCheckWhenToAmountExceedsThreshold(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $toWallet = Wallet::create(1, Currency::HUF);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        // 200 PLN → 16949.15 HUF − 178.41 spread = 16770.74 HUF > 15000
        $transaction = $this->makeServiceWithRealRates()->transfer(1, 1, 2, '200.00');

        self::assertSame('16770.74', $transaction->getToAmount()->toString());
        self::assertTrue($transaction->requiresAntiFraudCheck());
        self::assertSame(TransactionStatus::FRAUD_REVIEW, $transaction->getStatus());
    }

    public function testTransferThrowsWhenAmountHasTooManyDecimalPlaces(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, Wallet::create(1, Currency::PLN)],
                [2, Wallet::create(1, Currency::EUR)],
            ]);

        $this->walletRepository->expects(self::never())->method('save');
        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(InvalidMoneyAmountException::class);
        $this->expectExceptionMessage('Amount has too many decimal places for PLN.');

        $this->transferService->transfer(1, 1, 2, '10.001');
    }

    public function testTransferThrowsWhenAmountHasDecimalsForJpyWallet(): void
    {
        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, Wallet::create(1, Currency::JPY)],
                [2, Wallet::create(1, Currency::PLN)],
            ]);

        $this->walletRepository->expects(self::never())->method('save');
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
        $fromWallet = Wallet::create(1, Currency::PLN);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [99, null],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 99 not found.');

        $this->transferService->transfer(1, 1, 99, '100.00');
    }

    public function testTransferThrowsWhenFromWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(2, Currency::PLN);

        $this->walletRepository
            ->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn($fromWallet);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 1 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    public function testTransferThrowsWhenToWalletBelongsToOtherUser(): void
    {
        $fromWallet = Wallet::create(1, Currency::PLN);
        $toWallet = Wallet::create(2, Currency::EUR);

        $this->walletRepository
            ->method('findById')
            ->willReturnMap([
                [1, $fromWallet],
                [2, $toWallet],
            ]);

        $this->transactionRepository->expects(self::never())->method('save');

        $this->expectException(WalletNotFoundException::class);
        $this->expectExceptionMessage('Wallet 2 not found.');

        $this->transferService->transfer(1, 1, 2, '100.00');
    }

    private function makeServiceWithRealRates(): TransferService
    {
        return new TransferService(
            $this->walletRepository,
            $this->transactionRepository,
            new ExchangeRateService(),
            new SpreadService(),
        );
    }
}
```

Uwaga do `testTransferSuccessfully`: to przepisany test z HEAD, który **już na HEAD failuje** (oczekuje braku `setBalance` — część przyszłej poprawki podwójnego księgowania). Ma dalej failować z komunikatem `App\Entity\Wallet::setBalance(...) was not expected to be called`. Oczekiwane `toAmount` poprawione z niespójnego `'248.3775'` na `'249.00'` (= 1000.00 × 0.25 − 1.00), żeby po przyszłej poprawce test mógł przejść.

`tests/Service/DepositServiceTest.php` — dodaj importy `use App\Exception\InvalidMoneyAmountException;`, `use App\ValueObject\Money;`; zmień dwa testy, dopisz dwa:

```php
        $result = $this->depositService->deposit($userId, 1, '500.00');

        self::assertSame('500.00', $result->getBalance()->toString());
        self::assertNotNull($result->getLastActivityAt());
```

```php
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
```

`tests/Service/TransactionProcessorServiceTest.php` — przywróć wersję z HEAD (`git checkout HEAD -- tests/Service/TransactionProcessorServiceTest.php`), potem: dodaj `use App\ValueObject\ExchangeRate;`, `use App\ValueObject\Money;`; zamień `setBalance(500.0)` → `setBalance(Money::of('500.00', Currency::PLN))` (3 miejsca), `setBalance(100.0)` → `setBalance(Money::of('100.00', Currency::EUR))`; asercje w `testCompleteUpdatesWalletBalancesAndSetsCompletedStatus`:

```php
        self::assertSame('400.00', $fromWallet->getBalance()->toString());
        self::assertSame('125.00', $toWallet->getBalance()->toString());
```

i `makeTransaction()`:

```php
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
```

`testRejectSetsRejectedStatus` zostaw bez zmian — failuje już na HEAD (poza zakresem).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Service/TransferServiceTest.php tests/Service/DepositServiceTest.php tests/Service/TransactionProcessorServiceTest.php`
Expected: wiele FAIL/Error (`Unsupported operand types: App\ValueObject\Money - float` itp.).

- [ ] **Step 3: Implement services**

`src/Service/TransferService.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Transaction;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletNotFoundException;
use App\Repository\TransactionRepositoryInterface;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use RoundingMode;

readonly class TransferService
{
    public const string ANTI_FRAUD_THRESHOLD = '15000';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
        private TransactionRepositoryInterface $transactionRepository,
        private ExchangeRateService $exchangeRateService,
        private SpreadService $spreadService,
    ) {
    }

    /**
     * @throws WalletNotFoundException
     * @throws InvalidMoneyAmountException
     */
    public function transfer(
        int $userId,
        int $fromWalletId,
        int $toWalletId,
        string $fromAmount,
    ): Transaction {
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
        $exchangeRate = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
        $grossToAmount = $fromMoney->convertTo($toCurrency, $exchangeRate, RoundingMode::HalfAwayFromZero);
        $spread = $this->spreadService->calculateSpread($grossToAmount, $fromCurrency, $toCurrency);
        $toAmount = $grossToAmount->subtract($spread);

        $fromWallet->setBalance($fromWallet->getBalance()->subtract($fromMoney));
        $toWallet->setBalance($toWallet->getBalance()->add($toAmount));

        $this->walletRepository->save($fromWallet);
        $this->walletRepository->save($toWallet);

        $transaction = Transaction::create(
            fromWalletId: $fromWalletId,
            toWalletId: $toWalletId,
            fromAmount: $fromMoney,
            toAmount: $toAmount,
            spread: $spread,
            exchangeRate: $exchangeRate,
            requiresAntiFraudCheck: $toAmount->isGreaterThan(Money::of(self::ANTI_FRAUD_THRESHOLD, $toCurrency)),
        );

        $this->transactionRepository->save($transaction);

        return $transaction;
    }
}
```

`src/Service/DepositService.php` — zastąp całą zawartość:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Wallet;
use App\Exception\InvalidMoneyAmountException;
use App\Exception\WalletBlockedException;
use App\Exception\WalletNotFoundException;
use App\Repository\WalletRepositoryInterface;
use App\ValueObject\Money;
use DateTimeImmutable;

readonly class DepositService
{
    public const string MAX_AMOUNT = '10000';

    public function __construct(
        private WalletRepositoryInterface $walletRepository,
    ) {
    }

    /**
     * @throws WalletNotFoundException
     * @throws WalletBlockedException
     * @throws InvalidMoneyAmountException
     */
    public function deposit(int $userId, int $walletId, string $amount): Wallet
    {
        $wallet = $this->walletRepository->findById($walletId);
        if (null === $wallet || $wallet->getUserId() !== $userId) {
            throw new WalletNotFoundException($walletId);
        }

        if ($wallet->isBlocked()) {
            throw new WalletBlockedException($walletId);
        }

        $wallet->setBalance($wallet->getBalance()->add(Money::of($amount, $wallet->getCurrency())));
        $wallet->setLastActivityAt(new DateTimeImmutable());
        $this->walletRepository->save($wallet);

        return $wallet;
    }
}
```

`src/Service/TransactionProcessorService.php` — w `complete()` zamień dwie linie sald:

```php
        $fromWallet->setBalance($fromWallet->getBalance()->subtract($transaction->getFromAmount()));
        $fromWallet->setLastActivityAt(new DateTimeImmutable());

        $toWallet->setBalance($toWallet->getBalance()->add($transaction->getToAmount()));
        $toWallet->setLastActivityAt(new DateTimeImmutable());
```

- [ ] **Step 4: Run tests to verify**

Run: `php bin/phpunit tests/Service`
Expected: wszystko zielone **poza dokładnie dwoma** znanymi failami:
- `TransactionProcessorServiceTest::testRejectSetsRejectedStatus` — `findById() was expected to be invoked once but was never invoked`
- `TransferServiceTest::testTransferSuccessfully` — `Wallet::setBalance(...) was not expected to be called`

Jeśli failuje cokolwiek innego — napraw przed commitem.

- [ ] **Step 5: Commit**

```bash
git add src/Service/TransferService.php src/Service/DepositService.php src/Service/TransactionProcessorService.php tests/Service/TransferServiceTest.php tests/Service/DepositServiceTest.php tests/Service/TransactionProcessorServiceTest.php
git commit -m "refactor: use Money in transfer, deposit and transaction processing

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Kontroler — walidacja kwot (string lub liczba JSON) i mapowanie błędów

**Files:**
- Modify: `src/Controller/WalletController.php`
- Test: `tests/Controller/WalletControllerTest.php`

**Interfaces:**
- Consumes: `Money::isValidFormat()`, `InvalidMoneyAmountException`, `DepositService::MAX_AMOUNT` (string), `TransferService::transfer()`, `DepositService::deposit()`.
- Produces: brak nowych publicznych API; zachowanie HTTP zgodne z tabelą „Zmiany widoczne w API” w specu.

- [ ] **Step 1: Update tests (failing)**

`tests/Controller/WalletControllerTest.php` — importy dodatkowe:

```php
use App\Exception\InvalidMoneyAmountException;
use App\ValueObject\ExchangeRate;
use App\ValueObject\Money;
use Generator;
use PHPUnit\Framework\Attributes\DataProvider;
```

W `testTransferSuccessfully` zastąp budowę transakcji:

```php
        $transaction = new Transaction(
            id: 42,
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('25.12', Currency::EUR),
            spread: Money::of('0.13', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            status: TransactionStatus::PENDING,
            requiresAntiFraudCheck: false,
            antiFraudCheckedAt: null,
            createdAt: new DateTimeImmutable(),
        );
```

i dopisz na końcu tego testu:

```php
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('100.00', $data['fromAmount']);
        self::assertSame('25.12', $data['toAmount']);
        self::assertSame('0.13', $data['spread']);
        self::assertSame('0.250000', $data['exchangeRate']);
```

Nowe testy (dopisz przed zamykającą klamrą klasy):

```php
    /**
     * @throws Throwable
     */
    public function testListReturnsBalancesAsStrings(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());
        $wallet = Wallet::create(1, Currency::PLN);
        $wallet->setBalance(Money::of('1250.5', Currency::PLN));

        $this->walletRepository
            ->method('findByUserId')
            ->willReturn([$wallet]);

        $response = $this->controller->list($user);

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('1250.50', $data[0]['balance']);
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('jsonNumberAmountProvider')]
    public function testTransferAcceptsAmountAsJsonNumber(int|float $amount, string $expectedAmount): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->expects(self::once())
            ->method('transfer')
            ->with(1, 1, 2, $expectedAmount)
            ->willReturn($this->makeTransaction());

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => $amount,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(201, $response->getStatusCode());
    }

    public static function jsonNumberAmountProvider(): Generator
    {
        yield 'integer' => [100, '100'];
        yield 'float' => [100.5, '100.5'];
        yield 'float with zero fraction' => [100.0, '100'];
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('invalidAmountProvider')]
    public function testTransferReturnsBadRequestWhenAmountIsNotAPositiveDecimal(mixed $amount): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService->expects(self::never())->method('transfer');

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => $amount,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    #[DataProvider('invalidAmountProvider')]
    public function testDepositReturnsBadRequestWhenAmountIsNotAPositiveDecimal(mixed $amount): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService->expects(self::never())->method('deposit');

        $request = new Request(content: json_encode(['amount' => $amount], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount must be a positive number.', $data['error']);
    }

    public static function invalidAmountProvider(): Generator
    {
        yield 'zero string' => ['0.00'];
        yield 'zero number' => [0];
        yield 'negative zero number' => [-0.0];
        yield 'exponent string' => ['1e3'];
        yield 'exponent number' => [1e25];
        yield 'leading space' => [' 100'];
        yield 'boolean' => [true];
        yield 'array' => [['100']];
    }

    /**
     * @throws Throwable
     */
    public function testTransferReturnsBadRequestWhenAmountTooPreciseForCurrency(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->transferService
            ->method('transfer')
            ->willThrowException(InvalidMoneyAmountException::tooManyDecimalPlaces(Currency::PLN));

        $request = new Request(content: json_encode([
            'fromWalletId' => 1,
            'toWalletId' => 2,
            'amount' => '10.001',
        ], JSON_THROW_ON_ERROR));
        $response = $this->controller->transfer($request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount has too many decimal places for PLN.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountTooPreciseForCurrency(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->method('deposit')
            ->willThrowException(InvalidMoneyAmountException::tooManyDecimalPlaces(Currency::JPY));

        $request = new Request(content: json_encode(['amount' => '100.50'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount has too many decimal places for JPY.', $data['error']);
    }

    /**
     * @throws Throwable
     */
    public function testDepositAcceptsAmountAsJsonNumber(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->expects(self::once())
            ->method('deposit')
            ->with(1, 5, '500')
            ->willReturn(Wallet::create(1, Currency::PLN));

        $request = new Request(content: json_encode(['amount' => 500], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testDepositAcceptsExactlyMaxAmount(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService
            ->expects(self::once())
            ->method('deposit')
            ->with(1, 5, '10000.00')
            ->willReturn(Wallet::create(1, Currency::PLN));

        $request = new Request(content: json_encode(['amount' => '10000.00'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * @throws Throwable
     */
    public function testDepositReturnsBadRequestWhenAmountJustAboveMax(): void
    {
        $user = new User(1, 'test@example.com', ['ROLE_USER'], new DateTimeImmutable());

        $this->depositService->expects(self::never())->method('deposit');

        $request = new Request(content: json_encode(['amount' => '10000.01'], JSON_THROW_ON_ERROR));
        $response = $this->controller->deposit(5, $request, $user);

        self::assertSame(400, $response->getStatusCode());

        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Amount cannot exceed 10000.', $data['error']);
    }

    private function makeTransaction(): Transaction
    {
        return Transaction::create(
            fromWalletId: 1,
            toWalletId: 2,
            fromAmount: Money::of('100.00', Currency::PLN),
            toAmount: Money::of('25.12', Currency::EUR),
            spread: Money::of('0.13', Currency::EUR),
            exchangeRate: ExchangeRate::of(Currency::PLN, Currency::EUR, '0.25'),
            requiresAntiFraudCheck: false,
        );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php bin/phpunit tests/Controller/WalletControllerTest.php`
Expected: FAIL — m.in. `testTransferAcceptsAmountAsJsonNumber` (float `100.5` przekazany jako `(string)` OK, ale `1e25`/`' 100'`/`'1e3'` dziś przechodzą `is_numeric`), `testTransferReturnsBadRequestWhenAmountTooPreciseForCurrency` (wyjątek nieobsłużony), `boolean` (`TypeError`/200).

- [ ] **Step 3: Implement**

`src/Controller/WalletController.php` — importy:

```php
use App\Exception\InvalidMoneyAmountException;
use App\ValueObject\Money;
use BcMath\Number;
```

`transfer()` — od walidacji kwoty w dół:

```php
        $amount = self::parsePositiveAmount($data['amount']);
        if (null === $amount) {
            return new JsonResponse(['error' => 'Amount must be a positive number.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $transaction = $this->transferService->transfer(
                $user->getIdNotNull(),
                (int) $data['fromWalletId'],
                (int) $data['toWalletId'],
                $amount,
            );
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (InvalidMoneyAmountException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(new TransactionResponse($transaction), Response::HTTP_CREATED);
```

`deposit()` — od walidacji kwoty w dół:

```php
        $amount = self::parsePositiveAmount($data['amount']);
        if (null === $amount) {
            return new JsonResponse(['error' => 'Amount must be a positive number.'], Response::HTTP_BAD_REQUEST);
        }

        if (new Number($amount)->compare(DepositService::MAX_AMOUNT) > 0) {
            return new JsonResponse(['error' => sprintf('Amount cannot exceed %s.', DepositService::MAX_AMOUNT)], Response::HTTP_BAD_REQUEST);
        }

        try {
            $wallet = $this->depositService->deposit(
                $user->getIdNotNull(),
                $id,
                $amount,
            );
        } catch (WalletNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (WalletBlockedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (InvalidMoneyAmountException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse(new WalletResponse($wallet));
```

nowa metoda prywatna na końcu klasy:

```php
    /**
     * Accepts a decimal string or a JSON number; returns it as a decimal string when it is positive, null otherwise.
     * Currency precision is validated later by Money::of() in the service.
     */
    private static function parsePositiveAmount(mixed $amount): ?string
    {
        if (is_int($amount) || is_float($amount)) {
            $amount = (string) $amount;
        }

        if (!is_string($amount) || !Money::isValidFormat($amount)) {
            return null;
        }

        return new Number($amount)->compare(0) > 0 ? $amount : null;
    }
```

(`(string) 1e25` → `"1.0E+25"`, `(string) -0.0` → `"-0"` — pierwsze odpada na formacie, drugie na `compare(0) > 0`.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php bin/phpunit tests/Controller/WalletControllerTest.php`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add src/Controller/WalletController.php tests/Controller/WalletControllerTest.php
git commit -m "feat: validate amounts as decimals and map invalid money amounts to 400

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Migracja `wallets.balance` → `DECIMAL` i zaokrąglenie danych

**Files:**
- Create: `migrations/Version20261009120000.php`

**Interfaces:**
- Consumes: schemat z migracji `Version20260519*`.
- Produces: `wallets.balance DECIMAL(15,4) NOT NULL DEFAULT 0`; wszystkie kolumny kwot zaokrąglone do skali waluty (JPY 0, reszta 2).

- [ ] **Step 1: Write the migration**

`migrations/Version20261009120000.php`:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store wallet balances as DECIMAL and round money columns to currency scale';
    }

    public function up(Schema $schema): void
    {
        // Convert first, so ROUND() below works on exact DECIMAL values instead of DOUBLE.
        $this->addSql('ALTER TABLE `wallets` MODIFY `balance` DECIMAL(15,4) NOT NULL DEFAULT 0');

        // Currency scales (ISO 4217) are hardcoded on purpose: a migration must not change when application code does.
        $this->addSql("UPDATE `wallets` SET `balance` = ROUND(`balance`, IF(`currency` = 'JPY', 0, 2))");
        $this->addSql("UPDATE `company_wallets` SET `balance` = ROUND(`balance`, IF(`currency` = 'JPY', 0, 2))");
        $this->addSql(<<<SQL
            UPDATE `transactions` SET
                `from_amount` = ROUND(`from_amount`, IF(`from_currency` = 'JPY', 0, 2)),
                `to_amount`   = ROUND(`to_amount`,   IF(`to_currency` = 'JPY', 0, 2)),
                `spread`      = ROUND(`spread`,      IF(`to_currency` = 'JPY', 0, 2))
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Rounding done in up() is irreversible; only the column type is restored.
        $this->addSql('ALTER TABLE `wallets` MODIFY `balance` DOUBLE NOT NULL DEFAULT 0');
    }
}
```

- [ ] **Step 2: Run the migration on the dev database**

Run: `php bin/console doctrine:migrations:migrate -n`
Expected: `[OK] Successfully migrated to version: DoctrineMigrations\Version20261009120000`.

**Znany problem środowiska:** na dziś `php bin/console doctrine:migrations:status` kończy się błędem `Doctrine\DBAL\Schema\TableEditor::setOptions(): Argument #1 ($options) must be of type array, null given` (introspekcja schematu w DBAL, niezwiązane z tą zmianą). Jeśli `migrate` padnie z tym samym błędem — **zatrzymaj się i zgłoś to użytkownikowi**, nie naprawiaj w ramach tego planu i nie aplikuj SQL ręcznie bez jego zgody.

- [ ] **Step 3: Verify schema and data**

Run: `docker exec mariadb mariadb -uroot -proot app -e "SHOW COLUMNS FROM wallets LIKE 'balance'; SELECT id, currency, balance FROM wallets;"`
Expected: `balance | decimal(15,4) | NO | | 0.0000`; salda z co najwyżej 2 (JPY: 0) niezerowymi miejscami po przecinku.

- [ ] **Step 4: Verify down/up round-trip**

Run: `php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261009120000' --down -n && php bin/console doctrine:migrations:execute 'DoctrineMigrations\Version20261009120000' --up -n`
Expected: oba `[OK]`; po wszystkim kolumna znów `decimal(15,4)`.

- [ ] **Step 5: Commit**

```bash
git add migrations/Version20261009120000.php
git commit -m "feat: migrate wallet balance to DECIMAL and round money columns

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Dokumentacja, pełny suite, styl, smoke test API

**Files:**
- Modify: `README.md`, `docs/superpowers/specs/2026-10-09-money-value-object-design.md` (tylko jeśli coś odbiegło od specu)

- [ ] **Step 1: README**

W sekcji `## API Endpoints`, bezpośrednio pod tabelą endpointów (przed akapitem o Postmanie), dodaj:

```markdown
**Amounts.** Request `amount` may be a decimal string (`"100.50"`) or a JSON number (`100.5`). It must be positive and
must not have more decimal places than the wallet currency allows: `JPY` — 0, all other currencies — 2. Otherwise the
API returns `400`. All amounts in responses (`balance`, `fromAmount`, `toAmount`, `spread`) are strings formatted to
the currency's decimal places (e.g. `"1250.50"`, `"4333"` for JPY); `exchangeRate` is a string with 6 decimal places.
```

- [ ] **Step 2: Full test suite**

Run: `php bin/phpunit`
Expected: wszystko zielone poza **dokładnie dwoma** znanymi failami (`TransactionProcessorServiceTest::testRejectSetsRejectedStatus`, `TransferServiceTest::testTransferSuccessfully`) z tymi samymi komunikatami co na HEAD.

- [ ] **Step 3: No floats left for money**

Run: `grep -rnE "float|number_format|is_numeric" src/`
Expected: brak wyników.

- [ ] **Step 4: Code style**

Run: `vendor/bin/php-cs-fixer fix --dry-run --diff $(git diff --name-only 4fe0776 -- src tests migrations)`
(`4fe0776` = commit ze specem, sprzed implementacji.)
Expected: brak diffów. Jeśli są — `vendor/bin/php-cs-fixer fix <te same pliki>`, ponownie Step 2, i dołącz poprawki do commita w Step 6.

- [ ] **Step 5: Smoke test API**

```bash
php -S 127.0.0.1:8000 -t public >/tmp/claude-smoke-server.log 2>&1 &
SERVER_PID=$!
TOKEN=$(php bin/console app:create-user | grep -oE '[a-f0-9]{32,}' | tail -1)
AUTH="Authorization: Bearer $TOKEN"
curl -s -X POST -H "$AUTH" -d '{"currency":"PLN"}' http://127.0.0.1:8000/api/wallets; echo
curl -s -X POST -H "$AUTH" -d '{"currency":"JPY"}' http://127.0.0.1:8000/api/wallets; echo
curl -s -H "$AUTH" http://127.0.0.1:8000/api/wallets; echo
```

Odczytaj `id` portfeli PLN (`$PLN`) i JPY (`$JPY`) z odpowiedzi, potem:

```bash
curl -s -X POST -H "$AUTH" -d '{"amount":"500.00"}' http://127.0.0.1:8000/api/wallets/$PLN/deposit; echo
curl -s -X POST -H "$AUTH" -d '{"amount":100.5}' http://127.0.0.1:8000/api/wallets/$PLN/deposit; echo
curl -s -X POST -H "$AUTH" -d '{"amount":"0.001"}' http://127.0.0.1:8000/api/wallets/$PLN/deposit; echo
curl -s -X POST -H "$AUTH" -d "{\"fromWalletId\":$PLN,\"toWalletId\":$JPY,\"amount\":\"100.00\"}" http://127.0.0.1:8000/api/wallets/transfer; echo
curl -s -H "$AUTH" http://127.0.0.1:8000/api/wallets; echo
kill $SERVER_PID
```

Expected:
- deposit `"500.00"` → `"balance":"500.00"`; deposit `100.5` → `"balance":"600.50"`;
- deposit `"0.001"` → `{"error":"Amount has too many decimal places for PLN."}`;
- transfer → `"fromAmount":"100.00","toAmount":"4333","spread":"34","exchangeRate":"43.668122"`;
- lista → `"balance":"500.50"` (PLN) i `"balance":"4333"` (JPY) — jako stringi.

Jeśli `app:create-user` wypisuje token w innym formacie niż hex — odczytaj go ręcznie z outputu komendy.

- [ ] **Step 6: Commit**

```bash
git add README.md
git commit -m "docs: describe money amount format in API

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
