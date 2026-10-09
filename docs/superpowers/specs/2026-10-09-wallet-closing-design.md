# Zamykanie portfela (`DELETE /api/wallets/{id}`) — design

Data: 2026-10-09
Status: do przeglądu

## Cel

Pozwolić klientowi usunąć portfel. „Usunięcie” to **zamknięcie (soft delete)**: portfel znika z API i nie
przyjmuje operacji, ale zostaje w bazie razem z pełną historią transakcji (klucze obce `transactions → wallets`
mają `ON DELETE RESTRICT`, a historia finansowa nie może znikać). Zamknąć można tylko portfel pusty i bez
oczekujących przelewów — pieniądze nigdy nie znikają, a każdy przelew da się rozliczyć.

## Decyzje

| Kwestia                              | Decyzja                                                                                  |
|--------------------------------------|------------------------------------------------------------------------------------------|
| Semantyka usunięcia                  | Soft delete: `wallets.closed_at`                                                          |
| Warunki zamknięcia                   | Otwarty, niezablokowany, `balance = 0`, brak przelewów `pending`/`fraud_review` (z i do portfela) |
| Zamknięty portfel w API              | Jak nieistniejący: `404 Wallet {id} not found.`, nie ma go na liście                     |
| Ponowne założenie w tej samej walucie | Dozwolone — unikalność `(user, waluta)` tylko wśród otwartych portfeli                  |
| Odpowiedź sukcesu                    | `204 No Content`                                                                          |

## API

`DELETE /api/wallets/{id}` — trasa z `requirements: ['id' => '\d{1,18}']` (jak wpłata); wymaga tokena.
Kolejność sprawdzeń (pierwszy niespełniony warunek przerywa):

| Sytuacja                                                    | Status | Treść                                      |
|-------------------------------------------------------------|--------|--------------------------------------------|
| portfel nie istnieje / należy do innego użytkownika / już zamknięty | 404 | `{"error": "Wallet {id} not found."}`      |
| portfel zablokowany                                         | 422    | `{"error": "Wallet {id} is blocked."}`     |
| saldo różne od zera (także ujemne saldo historyczne)        | 422    | `{"error": "Wallet {id} has a non-zero balance."}` |
| istnieje przelew `pending`/`fraud_review` z lub do portfela | 422    | `{"error": "Wallet {id} has pending transfers."}` |
| zamknięty                                                   | 204    | puste body                                  |
| ID w ścieżce nienumeryczne lub > 18 cyfr                    | 404    | `{"error": "Not found."}` (istniejący listener) |

Zachowanie istniejących endpointów wobec zamkniętego portfela:

| Endpoint                         | Zamknięty portfel                                                  |
|----------------------------------|--------------------------------------------------------------------|
| `GET /api/wallets`               | pomijany                                                            |
| `POST /api/wallets`              | nie blokuje założenia nowego portfela w tej samej walucie (`201`)  |
| `POST /api/wallets/{id}/deposit` | `404 Wallet {id} not found.`                                        |
| `POST /api/wallets/transfer`     | jako źródło lub cel: `404 Wallet {id} not found.`                  |
| `app:process-transactions`       | `complete` traktuje zamknięty portfel jak zablokowany → `REJECTED` + zwolnienie rezerwacji (zabezpieczenie; normalnie nieosiągalne, bo zamknięcie wymaga braku oczekujących przelewów) |

## Model

### `Wallet`

- Nowe pole `?DateTimeImmutable $closedAt` (ostatni argument konstruktora po `createdAt`, domyślnie `null`
  w `create()`), gettery `getClosedAt()`, `isClosed(): bool`.
- `close(DateTimeImmutable $at): void` — sama pilnuje reguł:
  - już zamknięty → `LogicException` (błąd programisty; serwis zwraca wcześniej 404),
  - zablokowany → `WalletBlockedException`,
  - `balance ≠ 0` → `WalletNotEmptyException`,
  - `reserved ≠ 0` → `WalletHasPendingTransfersException`.
- `credit()` i `reserve()` na zamkniętym portfelu → `LogicException` (zabezpieczenie; serwisy zwracają wcześniej 404).
  `release()`/`settle()` bez zmian.

### Wyjątki — `src/Exception/`

| Wyjątek                               | Komunikat                                   | HTTP (listener) |
|---------------------------------------|---------------------------------------------|-----------------|
| `WalletNotEmptyException(int $id)`    | `Wallet {id} has a non-zero balance.`       | 422             |
| `WalletHasPendingTransfersException(int $id)` | `Wallet {id} has pending transfers.` | 422             |

Oba dopisane do tabeli `ApiExceptionListener::DOMAIN_EXCEPTION_STATUS`.

### Serwis — `WalletService::closeWallet(int $userId, int $walletId): void`

W `TransactionManagerInterface::transactional()`:
1. `walletRepository->lockForUpdate($walletId)`, `findById($walletId)`.
2. `null` / cudzy / zamknięty → `WalletNotFoundException($walletId)`.
3. zablokowany → `WalletBlockedException($walletId)`.
4. `balance ≠ 0` → `WalletNotEmptyException($walletId)`.
5. `transactionRepository->hasInFlightTransfers($walletId)` → `WalletHasPendingTransfersException($walletId)`.
6. `$wallet->close(now)`, `walletRepository->save($wallet)`.

Wyścig z przelewem jest wykluczony: `TransferService` blokuje te same wiersze portfeli (`lockForUpdate`),
więc złożenie przelewu i zamknięcie wykonują się jedno po drugim; po zamknięciu przelew dostaje 404.

### Pozostałe serwisy

- `TransferService` i `DepositService`: warunek „nie istnieje / cudzy” rozszerzony o `isClosed()` → `WalletNotFoundException`.
- `TransactionProcessorService::complete()`: warunek odrzucenia `isBlocked()` rozszerzony o `isClosed()`.
- `WalletService::createWallet()`: sprawdzenie duplikatu tylko wśród otwartych portfeli.

### Repozytoria

- `WalletRepository`: odczyt i zapis `closed_at` (`update()` zapisuje `closed_at`; `insert()` nie dotyka kolumny
  wyliczanej). `findByUserId()` → zwraca tylko otwarte (jedyny użytkownik: lista w API).
  `findByUserIdAndCurrency()` → tylko otwarte. `findById()` → dowolny (potrzebny procesorowi i historii).
- `TransactionRepositoryInterface::hasInFlightTransfers(int $walletId): bool` — istnieje transakcja ze statusem
  `pending`/`fraud_review`, w której portfel jest źródłem **lub** celem.

### Migracja

`up()`:
1. `ALTER TABLE wallets ADD closed_at DATETIME NULL`.
2. `ALTER TABLE wallets ADD open_flag TINYINT AS (IF(closed_at IS NULL, 1, NULL)) PERSISTENT` — 1 dla otwartych,
   `NULL` dla zamkniętych; MariaDB nie ma częściowych indeksów, a `NULL` w indeksie unikalnym się nie konfliktuje.
3. `ADD UNIQUE KEY wallet_user_currency_open_unique (user_id, currency, open_flag)`.
4. `DROP INDEX wallet_user_currency_unique` (po kroku 3 — klucz obcy `fk_wallets_user_id` potrzebuje indeksu
   zaczynającego się od `user_id`, który zapewnia nowy indeks).

`down()`: odwrotnie (przywrócenie `wallet_user_currency_unique`, usunięcie `open_flag` i `closed_at`). Uwaga
w migracji: `down()` nie powiedzie się, jeśli istnieją dwa portfele w tej samej walucie u jednego użytkownika
(zamknięty + nowy) — to świadome ograniczenie.

## Testy

- `WalletTest`: `close()` (pusty → zamknięty; każdy wyjątek; ponowne zamknięcie), `credit`/`reserve` na zamkniętym.
- `WalletServiceTest`: każda gałąź `closeWallet` (kolejność sprawdzeń), blokada przed odczytem, jedna transakcja DB;
  `createWallet` przy zamkniętym portfelu w tej samej walucie.
- `TransferServiceTest`, `DepositServiceTest`: zamknięty portfel → 404; `TransactionProcessorServiceTest`:
  zamknięty portfel przy `complete` → `REJECTED` + zwolnienie rezerwacji.
- Integracyjne (`app_test`): migracja — dwa portfele w tej samej walucie (zamknięty + otwarty) dozwolone, dwa
  otwarte → naruszenie unikalności; `hasInFlightTransfers` (z, do, rozliczone nie liczą się); zapis/odczyt `closed_at`.
- Funkcjonalne (HTTP): każdy wiersz tabeli API, `DELETE` dwa razy (204, potem 404), lista bez zamkniętego,
  wpłata/przelew na zamknięty → 404, ponowne `POST /api/wallets` w tej samej walucie → 201. Istniejący
  `WalletApiContractTest` bez zmian.

## Dokumentacja

- `docs/business-logic.md`: sekcja „Closing a wallet” (warunki, skutki, 404 dla zamkniętego).
- README: wiersz `DELETE /api/wallets/{id}` w tabeli endpointów.
- `exchange-api.postman_collection.json`: nowe żądanie „Close wallet”.

## Poza zakresem

- Ponowne otwarcie zamkniętego portfela.
- Endpoint historii transakcji (zamknięty portfel nie jest w nim widoczny, bo endpointu nie ma).
- Zamykanie z niezerowym saldem (przeniesienie środków).

## Kryteria sukcesu

- Pełny suite zielony, `WalletApiContractTest` bez zmian.
- Żaden przypadek z tabeli API nie zwraca 500.
- Zamknięcie nie zmienia żadnego salda ani transakcji.
