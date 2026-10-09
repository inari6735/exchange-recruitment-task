# Rezerwacja środków i logika przelewów — design

Data: 2026-10-09
Status: do przeglądu
Poprzednia zmiana: `2026-10-09-money-value-object-design.md` (kwoty jako `Money`)

## Cel

Naprawić logikę biznesową przelewów, wpłat i przetwarzania transakcji tak, żeby pieniądze
**nigdy nie powstawały ani nie znikały**: każdy przelew księguje się dokładnie raz, odrzucony nie zostawia
śladu w saldach, saldo nie schodzi poniżej zera, spread trafia do firmy, a równoległe żądania nie psują sald.

## Problemy, które rozwiązujemy

| #  | Problem (stan na `main`)                                                                 | Gdzie rozwiązany                      |
|----|------------------------------------------------------------------------------------------|---------------------------------------|
| 1  | Podwójne księgowanie: `transfer()` i `complete()` oba zmieniają salda                    | Model rezerwacji                      |
| 2  | `reject()` nie cofa przesunięcia środków                                                 | Model rezerwacji                      |
| 3  | Brak kontroli salda — przelew na minus (potwierdzone: saldo `-995.00`)                   | `Wallet::reserve()`                   |
| 4  | Przelew z zablokowanego portfela przechodzi                                              | `Wallet::reserve()`                   |
| 5  | Spread nie trafia do `company_wallets`                                                   | `complete()`                          |
| 6  | Brak transakcji DB i blokad — wyścigi przy równoległych żądaniach                        | Spójność i współbieżność              |
| 7  | Przelew na ten sam portfel jest dozwolony                                                | Walidacja transferu                   |
| 8  | Próg anti-fraud porównywany w walucie docelowej, jedna wartość dla wszystkich walut      | Limity per waluta                     |
| 9  | Limit wpłaty 10000 niezależny od waluty                                                  | Limity per waluta                     |
| 10 | Równoległe `app:process-transactions` mogą przetworzyć tę samą transakcję dwa razy       | Spójność i współbieżność              |

## Decyzje

| Kwestia                                  | Decyzja                                                                                    |
|------------------------------------------|--------------------------------------------------------------------------------------------|
| Co się dzieje ze środkami przy `pending` | **Rezerwacja**: kwota blokowana na portfelu źródłowym, saldo bez zmian                     |
| Zablokowany portfel docelowy             | Nie może przyjmować przelewów (ani przy złożeniu, ani przy realizacji)                      |
| Progi (anti-fraud, limit wpłaty)         | Osobna wartość dla każdej waluty, w konfiguracji                                           |
| Kwota sprawdzana progiem anti-fraud      | `fromAmount` w walucie źródłowej (to ona opisuje ryzyko)                                   |
| Moment zarobku firmy                     | Spread księgowany na `company_wallets` przy `complete`, w walucie docelowej                |

## Model rezerwacji

`Wallet` dostaje pole `reserved: Money` (ta sama waluta co `balance`).

- **Saldo** (`balance`) — pieniądze faktycznie na portfelu.
- **Zarezerwowane** (`reserved`) — suma `fromAmount` przelewów wychodzących, które czekają (`pending`, `fraud_review`).
- **Dostępne** (`available = balance − reserved`) — ile można jeszcze wydać.

Niezmiennik portfela: `0 ≤ reserved ≤ balance`.

### Cykl życia transakcji

```
                 transfer()
                     │  reserve(fromAmount) na źródle
                     ▼
     fromAmount > próg anti-fraud waluty źródłowej?
          │ nie                       │ tak
          ▼                           ▼
      PENDING                   FRAUD_REVIEW
          │                           │  (decyzja operatora w app:process-transactions)
          ├──────────────┬────────────┤
          ▼              ▼            ▼
      complete()      reject()    reject()
          │              │
          ▼              ▼
      COMPLETED      REJECTED
```

`COMPLETED` i `REJECTED` są końcowe — żadna operacja nie zmienia już ani transakcji, ani sald.

### Efekt operacji na saldach

| Operacja     | Źródło: `balance` | Źródło: `reserved` | Cel: `balance` | Firma (waluta celu) |
|--------------|-------------------|--------------------|----------------|---------------------|
| `transfer()` | —                 | `+ fromAmount`     | —              | —                   |
| `complete()` | `− fromAmount`    | `− fromAmount`     | `+ toAmount`   | `+ spread`          |
| `reject()`   | —                 | `− fromAmount`     | —              | —                   |
| `deposit()`  | `+ amount`        | —                  | —              | —                   |

Bilans przelewu po `complete()`: klient oddaje `fromAmount` w walucie źródłowej, dostaje `toAmount`
w walucie docelowej, firma zatrzymuje `spread`, przy czym `toAmount + spread = fromAmount × kurs`
(zaokrąglone do skali waluty docelowej — bez zmian względem obecnego wyliczenia).

## Operacje domenowe na `Wallet`

`setBalance()` znika z publicznego API — salda zmieniają się tylko przez metody, które same pilnują reguł:

| Metoda                  | Działanie                              | Wyjątki                                                                    |
|-------------------------|----------------------------------------|----------------------------------------------------------------------------|
| `reserve(Money $a)`     | `reserved += a`                        | zablokowany → `WalletBlockedException`; `a > available` → `InsufficientFundsException` |
| `release(Money $a)`     | `reserved −= a`                        | `a > reserved` → `LogicException` (błąd programisty)                       |
| `settle(Money $a)`      | `balance −= a`, `reserved −= a`        | `a > reserved` → `LogicException`                                          |
| `credit(Money $a)`      | `balance += a`                         | zablokowany → `WalletBlockedException`                                     |
| `getAvailable(): Money` | `balance − reserved`                   | —                                                                          |

Wspólne: kwota musi być **dodatnia** (`> 0`), w walucie portfela (`CurrencyMismatchException`).
`settle()` celowo **nie** sprawdza blokady — patrz „Realizacja” niżej (zablokowane źródło → odrzucenie, nie
rozliczenie). `credit()` służy zarówno wpłacie, jak i przyjęciu przelewu — blokada działa w obu przypadkach,
co zachowuje dzisiejsze zachowanie wpłaty (`422`).

`CompanyWallet` dostaje analogicznie `credit(Money)`; zapis do bazy dalej przez atomowe
`CompanyWalletRepository::addToBalance(Money)` (`balance = balance + :amount`).

## Operacje serwisowe

### Złożenie przelewu — `TransferService::transfer()`

Kolejność walidacji (pierwszy niespełniony warunek przerywa):

1. `fromWalletId === toWalletId` → `SameWalletTransferException` → **400** `Cannot transfer to the same wallet.`
2. Portfel źródłowy istnieje i należy do użytkownika → inaczej **404** `Wallet {id} not found.`
3. Portfel docelowy istnieje i należy do użytkownika → inaczej **404**.
4. Kwota poprawna dla waluty źródła (`Money::of`) → inaczej **400** (bez zmian).
5. Portfel docelowy niezablokowany → inaczej **422** `Wallet {id} is blocked.`
6. `fromWallet->reserve(fromAmount)`:
   - źródło zablokowane → **422** `Wallet {id} is blocked.`
   - za mało środków → **422** `Insufficient funds in wallet {id}.`

Potem: wyliczenie kursu, spreadu i `toAmount` (bez zmian), status `FRAUD_REVIEW` gdy
`fromAmount > antiFraudThreshold(fromCurrency)`, inaczej `PENDING`; zapis portfela źródłowego i transakcji.
Saldo portfela docelowego **nie** jest zmieniane ani zapisywane.

### Realizacja — `TransactionProcessorService::complete()`

1. Portfele nie istnieją → jak `reject()` (bez zmian względem dziś).
2. Źródło lub cel zablokowane → `reject()` (rezerwacja zwolniona, status `REJECTED`).
3. Inaczej: `from->settle(fromAmount)`, `to->credit(toAmount)`, `companyWallet += spread`,
   `lastActivityAt` obu portfeli = teraz, status `COMPLETED`.

### Odrzucenie — `TransactionProcessorService::reject()`

Jeśli portfel źródłowy istnieje: `from->release(fromAmount)` i zapis portfela. Status `REJECTED`.
`antiFraudCheckedAt` ustawiane jak dziś (tylko gdy `requiresAntiFraudCheck`).

### Wpłata — `DepositService::deposit()`

1. Portfel istnieje i należy do użytkownika → inaczej **404**.
2. Kwota poprawna dla waluty (`Money::of`) → inaczej **400**.
3. Kwota ≤ `depositLimit(waluta)` → inaczej **400** `Amount cannot exceed {limit} {CUR}.` (np. `2500.00 EUR`).
4. `wallet->credit(amount)` — zablokowany → **422** (bez zmian).

Sprawdzenie limitu przenosi się z kontrolera do serwisu, bo kontroler nie zna waluty portfela.
Kontroler dalej odrzuca kwoty niedodatnie i źle sformatowane (**400**).

## Limity per waluta

Nowa klasa `App\Service\TransactionLimits` z metodami `antiFraudThreshold(Currency): Money`
i `depositLimit(Currency): Money`. Wartości w `config/services.yaml` jako parametry
(`app.limits.anti_fraud_threshold`, `app.limits.deposit`) — zmiana limitu nie wymaga zmiany kodu.
Brak wartości dla którejś waluty → wyjątek przy starcie kontenera (walidacja w konstruktorze).

Proponowane wartości — zaokrąglone ekwiwalenty dzisiejszych `15000` / `10000` traktowanych jako PLN:

| Waluta | Próg anti-fraud (`fromAmount >`) | Limit pojedynczej wpłaty |
|--------|----------------------------------|--------------------------|
| PLN    | 15 000                           | 10 000                   |
| EUR    | 3 500                            | 2 500                    |
| USD    | 4 000                            | 2 500                    |
| GBP    | 3 000                            | 2 000                    |
| CHF    | 3 200                            | 2 000                    |
| JPY    | 650 000                          | 450 000                  |
| HUF    | 1 250 000                        | 850 000                  |

Zmiana zachowania względem dziś: próg anti-fraud porównywany z `fromAmount` (waluta źródłowa), a nie z
`toAmount` (waluta docelowa).

## Spójność i współbieżność

### Transakcje bazodanowe

Nowy port `App\Repository\TransactionManagerInterface::transactional(callable $operation): mixed`,
implementacja na DBAL `Connection::transactional()`. Każda operacja zmieniająca salda (`transfer`,
`deposit`, `complete`, `reject`) wykonuje się w całości w jednej transakcji DB — wyjątek w środku cofa wszystko.

### Blokady wierszy

`WalletRepositoryInterface::lockForUpdate(int ...$ids): void` — `SELECT id FROM wallets WHERE id IN (...) ORDER BY id FOR UPDATE`.
Wywoływane na początku transakcji DB, **przed** odczytem portfeli przez `findById()`; kolejność rosnąca po
`id` eliminuje deadlock dwóch przeciwnych przelewów (A→B i B→A).

### Jednokrotne przetworzenie transakcji

`TransactionRepository::save()` dla istniejącej transakcji zmienia status warunkowo:
`UPDATE … WHERE id = :id AND status IN ('pending', 'fraud_review')`. Gdy zmienionych wierszy jest 0 —
transakcja została już rozliczona przez kogoś innego → `TransactionAlreadyProcessedException`, cała transakcja
DB (także zmiany sald) jest cofana. Drugi równoległy `app:process-transactions` nie zaksięguje jej ponownie;
komenda wypisuje ostrzeżenie i idzie dalej.

Decyzja operatora w fraud review zapada **poza** transakcją DB — blokady są brane dopiero przy jej
zastosowaniu, więc pytanie w konsoli nie trzyma wierszy.

## Zmiany w API

| Sytuacja                                    | Dziś                         | Po zmianie                                        |
|---------------------------------------------|------------------------------|---------------------------------------------------|
| Przelew > dostępne środki                   | `201`, saldo na minusie      | `422` `Insufficient funds in wallet {id}.`        |
| Przelew z zablokowanego portfela            | `201`                        | `422` `Wallet {id} is blocked.`                   |
| Przelew na zablokowany portfel              | `201`                        | `422` `Wallet {id} is blocked.`                   |
| Przelew na ten sam portfel                  | `201`                        | `400` `Cannot transfer to the same wallet.`       |
| Salda po złożeniu przelewu                  | od razu przesunięte          | bez zmian, rośnie `reserved` źródła               |
| Wpłata ponad limit                          | `400`, limit `10000` zawsze  | `400`, limit waluty, np. `Amount cannot exceed 2500.00 EUR.` |
| Odpowiedź portfela                          | `balance`                    | `balance`, **`reserved`**, **`available`** (stringi) |

## Migracja

`up()`:
1. `ALTER TABLE wallets ADD reserved DECIMAL(15,4) NOT NULL DEFAULT 0`.
2. **Transakcje w locie** (`pending`, `fraud_review`) zostały złożone w starym modelu, czyli ich środki już
   przesunięto. Migracja przeprowadza je do nowego modelu: cofa przesunięcie
   (źródło `balance += from_amount`, cel `balance −= to_amount`) i zakłada rezerwację
   (źródło `reserved += from_amount`). Dzięki temu późniejsze `complete()`/`reject()` rozlicza je poprawnie.

`down()`: odwrotność kroku 2 (dla transakcji nadal w locie) i `DROP COLUMN reserved`.

Uwaga: portfele, które dziś mają ujemne saldo (na deweloperskiej bazie są 2 — ślad błędu #3), zostają
bez zmian. Niezmiennik `reserved ≤ balance` nie jest dla nich wymuszany przez migrację; każda nowa rezerwacja
z takiego portfela skończy się `422 Insufficient funds`.

## Testy

TDD jak poprzednio. Nowe testy:
- `WalletTest` — `reserve`/`release`/`settle`/`credit`/`getAvailable`, każdy wyjątek, niezmiennik.
- `TransactionLimitsTest` — wartości per waluta, brak waluty w konfiguracji.
- `TransferServiceTest` — wszystkie przypadki z tabeli „Zmiany w API”, rezerwacja zamiast zmiany sald,
  próg anti-fraud z waluty źródłowej, blokady w rosnącej kolejności `id`.
- `TransactionProcessorServiceTest` — `complete` rozlicza rezerwację i spread, `reject` zwalnia rezerwację,
  zablokowany portfel przy `complete` → `REJECTED`, `TransactionAlreadyProcessedException` przy drugim przetworzeniu.
- `DepositServiceTest` — limit per waluta.
- Test integracyjny na prawdziwej bazie (`KernelTestCase`, baza `app_test`, każdy test w transakcji
  wycofywanej w `tearDown`): pełny cykl transfer → complete i transfer → reject, sprawdzenie sum sald
  i `company_wallets`; warunkowe `UPDATE` przy podwójnym przetworzeniu. Wymaga `DATABASE_URL` w `.env.test`
  (lub dziedziczenia z `.env`) i schematu w `app_test`.

### Dwa testy, które dziś failują

Oba opisują docelowe zachowanie i po tej zmianie mają przechodzić — z zachowaniem ich intencji:

- `TransferServiceTest::testTransferSuccessfully` — intencja: **złożenie przelewu nie zmienia sald**
  (`setBalance` nigdy). Zostaje. Szczegóły mocków, które opisują przypadkowy przebieg, a nie zachowanie
  (`getBalance` zwracające kolejno 5000/4000, dokładnie 2 zapisy portfela), zostają zastąpione asercjami
  na rezerwacji: `reserve(1000.00 PLN)` na źródle, brak zmian i zapisu celu.
- `TransactionProcessorServiceTest::testRejectSetsRejectedStatus` — intencja: **odrzucenie pobiera
  i zapisuje portfel źródłowy** (`findById(1)`, `save($wallet)`). Zostaje dosłownie, uzupełniona
  o asercję `release(fromAmount)`.

## Dokumentacja

`docs/business-logic.md` — trwały opis logiki dla osób czytających projekt (stany transakcji, rezerwacja,
efekty operacji na saldach, limity, spread, kody błędów API). Powstaje w ostatnim tasku planu, z treści tego
specu, i opisuje zachowanie już zaimplementowane. README linkuje do niego z sekcji „About”.

## Poza zakresem

- Kursy walut nadal zaszyte w `ExchangeRateService`.
- Aktualizacja zależności (`composer audit`: 15 zgłoszeń).
- Odpowiedzi HTML dla `401` bez tokena i `500` przy niepoprawnym JSON (osobna zmiana w warstwie HTTP).
- Drobne uwagi z przeglądu zmiany `Money` (rzutowanie liczby JSON zależne od `precision`, jawny
  tryb zaokrąglania w `ExchangeRate::of`, kwoty ponad zakres `DECIMAL(15,4)`).

## Kryteria sukcesu

- Pełny suite zielony — **wliczając** dwa dziś failujące testy.
- Dla każdego przelewu: suma zmian sald klienta i firmy w walucie docelowej równa `toAmount + spread`,
  a w walucie źródłowej `−fromAmount` — sprawdzone testem integracyjnym.
- Przetworzenie tej samej transakcji z dwóch niezależnie wczytanych kopii (symulacja dwóch równoległych
  `app:process-transactions`) daje jedno rozliczenie — sprawdzone testem integracyjnym.
- `docs/business-logic.md` opisuje zaimplementowane zachowanie.
