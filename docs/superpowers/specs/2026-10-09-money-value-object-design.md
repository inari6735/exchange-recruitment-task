# Money value object — design

Data: 2026-10-09
Status: zaakceptowany w rozmowie, do przeglądu

## Cel

Uspójnić reprezentację kwot pieniężnych w całej aplikacji. Dziś kwoty są trzymane mieszanie jako `float`
(`Wallet::$balance`, `CompanyWallet::$balance`, kolumna `wallets.balance DOUBLE`) i `string`
(`Transaction::$fromAmount` itd.), z niespójnymi zaokrągleniami (`number_format` do 2, 4 i 6 miejsc).

## Zakres

- **W zakresie:** wprowadzenie `Money` i `ExchangeRate` oraz zastąpienie nimi floatów i stringów w encjach,
  repozytoriach, serwisach, DTO i kontrolerze; migracja schematu; aktualizacja testów, README i kolekcji Postmana.
- **Poza zakresem:** zmiany logiki biznesowej. Znane błędy (podwójne księgowanie w `TransferService` +
  `TransactionProcessorService`, brak kontroli salda i blokady w transferze, brak zasilania `CompanyWallet`
  spreadem, próg anti-fraud w walucie docelowej, brak transakcji DB) zostają naprawione osobno.

## Decyzje

| Kwestia               | Decyzja                                                                          |
|-----------------------|----------------------------------------------------------------------------------|
| Arytmetyka            | `BcMath\Number` (rozszerzenie `bcmath`), bez zewnętrznych bibliotek              |
| Precyzja kwot         | Zależna od waluty, ISO 4217: `JPY` = 0, pozostałe = 2 (`Currency::scale()`)      |
| Precyzja kursu        | 6 miejsc, zaokrąglany **przed** użyciem w obliczeniach                           |
| Tryb zaokrąglania     | `RoundingMode::HalfAwayFromZero` (jak dzisiejszy `number_format`), zawsze jawny  |
| Zbyt precyzyjne wejście | Błąd (`InvalidMoneyAmountException`), nie ciche zaokrąglenie                   |
| Kwoty w JSON          | Stringi (np. `"1250.50"`)                                                         |

## Komponenty

### `App\Enum\Currency::scale(): int`

`JPY` → 0, pozostałe → 2.

### `App\ValueObject\Money` (`final readonly`)

Stan: `Number $amount` (zawsze w skali `currency->scale()`), `Currency $currency`.

- `static of(string $amount, Currency $currency): self`
  - format: `^-?\d+(\.\d+)?$`, inaczej `InvalidMoneyAmountException`;
  - cyfry ponad skalę waluty dozwolone tylko jeśli są zerami (`"12.5000"` OK dla PLN — tak zwraca MariaDB
    `DECIMAL(15,4)`; `"12.505"` → `InvalidMoneyAmountException`).
- `static zero(Currency $currency): self`
- `static isValidFormat(string $amount): bool` — ta sama reguła formatu, do użycia w kontrolerze.
- `add(Money): self`, `subtract(Money): self` — dokładne; inna waluta → `CurrencyMismatchException`.
- `convertTo(Currency $target, ExchangeRate $rate, RoundingMode $mode): self` — `$rate` musi mieć
  `from === $this->currency` i `to === $target`, inaczej `CurrencyMismatchException`.
- `percentage(Number $percent, RoundingMode $mode): self` — `amount × percent / 100`, zaokrąglone do skali waluty.
- `isGreaterThan(Money): bool`, `equals(Money): bool` — inna waluta → `CurrencyMismatchException`.
- `getCurrency(): Currency`, `toString(): string` — zawsze dokładnie `scale()` miejsc (`"12.50"`, JPY `"1250"`).

### `App\ValueObject\ExchangeRate` (`final readonly`)

Stan: `Currency $from`, `Currency $to`, `Number $rate` (skala 6).

- `static of(Currency $from, Currency $to, string $rate): self` — zaokrągla do 6 miejsc.
- `getFrom()`, `getTo()`, `getRate(): Number`, `toString(): string` (6 miejsc).

### Wyjątki

- `App\Exception\InvalidMoneyAmountException` — błąd wejścia, mapowany w kontrolerze na `400`.
- `App\Exception\CurrencyMismatchException` — błąd programisty, nie jest łapany (`500`).

## Zmiany w istniejącym kodzie

### Encje

- `Wallet::$balance`, `CompanyWallet::$balance`: `float` → `Money`. `setBalance(Money)` rzuca
  `CurrencyMismatchException`, gdy waluta różni się od waluty portfela. `create()` używa `Money::zero()`.
- `Transaction`: `fromAmount`, `toAmount`, `spread` → `Money`; `exchangeRate` → `ExchangeRate`.
  Pola `fromCurrency`/`toCurrency` zostają w konstruktorze (kolumny w bazie), ale `create()` wyprowadza je
  z `Money`, a konstruktor weryfikuje spójność.

### Serwisy

- `ExchangeRateService`: kursy jako stringi; `getExchangeRateBetween(from, to): ExchangeRate` liczone przez
  `Number::div` z zapasem skali, zaokrąglone do 6 miejsc w `ExchangeRate::of`. `getExchangeRate()` zwraca `Number`.
- `SpreadService::calculateSpread(Money $price, Currency $from, Currency $to): Money` — procent
  `0.5 / avgLiquidity` jako `Number` (skala 10), kwota przez `Money::percentage(..., HalfAwayFromZero)`.
  Ta sama waluta → `Money::zero`.
- `TransferService::transfer(...)`:
  ```php
  $fromAmount = Money::of($amount, $fromCurrency);
  $rate       = $this->exchangeRateService->getExchangeRateBetween($fromCurrency, $toCurrency);
  $gross      = $fromAmount->convertTo($toCurrency, $rate, RoundingMode::HalfAwayFromZero);
  $spread     = $this->spreadService->calculateSpread($gross, $fromCurrency, $toCurrency);
  $toAmount   = $gross->subtract($spread);
  ```
  Anti-fraud: `$toAmount->isGreaterThan(Money::of('15000', $toCurrency))` (semantyka bez zmian).
  Modyfikacja sald pozostaje tak jak dziś (poza zakresem).
- `DepositService::deposit()`: `Money::of($amount, $wallet->getCurrency())`; `MAX_AMOUNT` → `'10000'` (string).
- `TransactionProcessorService`: operacje na `Money` zamiast floatów, logika bez zmian.

### Repozytoria

- Odczyt: `Money::of((string) $row[...], $currency)`, `ExchangeRate::of($from, $to, (string) $row['exchange_rate'])`.
- Zapis: `->toString()`.
- `CompanyWalletRepository` analogicznie.

### Kontroler i DTO

- Walidacja kwot: `Money::isValidFormat()` + porównania na `Number` (dodatnia, ≤ `MAX_AMOUNT`) zamiast
  `is_numeric` / `(float)`.
- `InvalidMoneyAmountException` → `400` z komunikatem, np. `Amount has too many decimal places for PLN.`
- `WalletResponse.balance`, `TransactionResponse.fromAmount|toAmount|spread` → stringi z `Money::toString()`;
  `exchangeRate` → `ExchangeRate::toString()`.
- `ShowCompanyWalletCommand`: `Money::toString()` zamiast `number_format`.

### Migracja (nowa wersja)

`up()`:
1. Zaokrąglenie istniejących danych do skali waluty:
   `wallets.balance`, `company_wallets.balance` (wg `currency`), `transactions.from_amount` (wg `from_currency`),
   `transactions.to_amount` i `transactions.spread` (wg `to_currency`) —
   `ROUND(x, IF(currency = 'JPY', 0, 2))`.
2. `ALTER TABLE wallets MODIFY balance DECIMAL(15,4) NOT NULL DEFAULT 0`.

`down()`: `balance` z powrotem na `DOUBLE`; zaokrąglenia są nieodwracalne (komentarz w migracji).

Uwaga: krok 1 przepisuje historyczne transakcje — akceptowalne w środowisku deweloperskim, nie na produkcji.

### Środowisko

- `Dockerfile`: `bcmath` w `docker-php-ext-install`.
- `composer.json`: `"ext-bcmath": "*"` w `require`.
- Lokalnie: rozszerzenie `bcmath` musi być włączone (instaluje użytkownik).

### Dokumentacja

- README: kwoty w odpowiedziach API są stringami; precyzja zależna od waluty.
- `exchange-api.postman_collection.json`: przykłady kwot jako stringi zgodne z precyzją.

## Testy (TDD)

Nowe:
- `tests/ValueObject/MoneyTest.php` — parsowanie (poprawny format, `"1e5"`, spacje, pusty string, nadmiarowe
  zera, zbyt wysoka precyzja, JPY bez części ułamkowej), `toString`, add/subtract, niezgodność walut,
  `percentage`, `convertTo` z zaokrągleniem na granicy (`x.xx5`), porównania.
- `tests/ValueObject/ExchangeRateTest.php` — zaokrąglenie do 6 miejsc, `toString`.
- Test `Currency::scale()`.
- Przypadek referencyjny: transfer PLN → HUF przeliczony ręcznie (kurs, spread, kwota netto).

Do aktualizacji: testy encji, DTO, serwisów, kontrolera — typy `Money`, asercje na stringach.

`TransferServiceTest`: niezacommitowany, rozgrzebany wariant `KernelTestCase` (`testTransferElo` bez asercji,
pozostałe testy odwołują się do usuniętych mocków) zostaje zastąpiony wersją jednostkową na mockach (jak w HEAD),
zaktualizowaną do `Money`. Test integracyjny transferu na prawdziwej bazie — ewentualnie osobno, razem z naprawą
podwójnego księgowania.

## Kryteria sukcesu

- Brak `float` dla kwot i kursów w `src/` (poza ewentualnymi stałymi płynności w `SpreadService`).
- Wszystkie kolumny kwot to `DECIMAL`, wartości zawsze w skali waluty.
- `composer tests` przechodzi.
- Zachowanie biznesowe bez zmian, poza dokładnością, zaokrągleniami, odrzucaniem zbyt precyzyjnych kwot
  i formatem kwot w JSON.
