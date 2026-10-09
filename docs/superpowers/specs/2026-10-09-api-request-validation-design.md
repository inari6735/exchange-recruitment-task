# Walidacja żądań API i obsługa błędów — design

Data: 2026-10-09
Status: do przeglądu

## Cel

Uporządkować warstwę HTTP po symfonowemu: kontroler przyjmuje zwalidowane DTO żądania
(`#[MapRequestPayload]` + Symfony Validator) zamiast ręcznie parsować JSON, a mapowanie wyjątków na odpowiedzi
HTTP przenosi się z łańcuchów `try/catch` w kontrolerze do jednego listenera. **Każda dzisiejsza „normalna”
odpowiedź API zostaje co do znaku** (status + `{"error": "…"}`); zmieniają się tylko przypadki brzegowe, które
dziś kończą się błędem serwera albo mylącą odpowiedzią.

## Decyzje

| Kwestia                                   | Decyzja                                                                            |
|-------------------------------------------|------------------------------------------------------------------------------------|
| Mechanizm walidacji                       | DTO + `#[MapRequestPayload]` + atrybuty `#[Assert\…]`                              |
| Nowe zależności                           | `symfony/validator`, `symfony/serializer`; dev: `symfony/browser-kit` (testy HTTP) |
| Przypadki brzegowe (dziś 500 / mylące 404) | Naprawione na 400/404/415 z komunikatem w JSON                                     |
| `Content-Type`                            | Wymagany `application/json`; inaczej `415`                                          |
| Wyjątki domenowe                          | Mapowane na HTTP w listenerze, nie w kontrolerze                                    |

## Kontrakt błędów (bez zmian)

Każdy błąd API ma postać `{"error": "<komunikat>"}`. Komunikaty i statusy, które **muszą zostać identyczne**:

| Endpoint             | Sytuacja                              | Status | Komunikat                                           |
|----------------------|---------------------------------------|--------|-----------------------------------------------------|
| `POST /api/wallets`  | brak `currency` (nieobecne lub `null`) | 400    | `Missing required field: currency.`                 |
|                      | nieznana waluta                       | 400    | `Invalid currency.`                                 |
|                      | portfel w tej walucie już istnieje    | 409    | `Wallet for user {id} in currency {CUR} already exists.` |
| `POST …/transfer`    | brak pola (pierwsze brakujące w kolejności `fromWalletId`, `toWalletId`, `amount`) | 400 | `Missing required field: {field}.` |
|                      | kwota niedodatnia / zły format        | 400    | `Amount must be a positive number.`                 |
|                      | zbyt precyzyjna kwota                 | 400    | `Amount has too many decimal places for {CUR}.`     |
|                      | ten sam portfel                       | 400    | `Cannot transfer to the same wallet.`               |
|                      | portfel nie istnieje / cudzy          | 404    | `Wallet {id} not found.`                            |
|                      | portfel zablokowany                   | 422    | `Wallet {id} is blocked.`                           |
|                      | brak środków                          | 422    | `Insufficient funds in wallet {id}.`                |
| `POST …/{id}/deposit`| brak `amount`                         | 400    | `Missing required field: amount.`                   |
|                      | kwota niedodatnia / zły format        | 400    | `Amount must be a positive number.`                 |
|                      | zbyt precyzyjna kwota                 | 400    | `Amount has too many decimal places for {CUR}.`     |
|                      | ponad limit waluty                    | 400    | `Amount cannot exceed {limit} {CUR}.`               |
|                      | portfel nie istnieje / cudzy          | 404    | `Wallet {id} not found.`                            |
|                      | portfel zablokowany                   | 422    | `Wallet {id} is blocked.`                           |

Kolejność sprawdzania jak dziś: najpierw obecność **wszystkich** pól, potem formaty — odpowiedź zwraca pierwszy
błąd. Kwota przyjmowana jako string dziesiętny lub liczba JSON (jak dziś).

## Zmiany zachowania (wyłącznie przypadki brzegowe)

| Wejście                                          | Dziś                                  | Po zmianie                                                |
|--------------------------------------------------|---------------------------------------|-----------------------------------------------------------|
| brak / zły `Content-Type` (np. `curl -d` bez `-H`) | działa (body zawsze jako JSON)        | 415 `Unsupported content type, expected application/json.` |
| niepoprawny JSON (`{bad`), puste body              | 500 (HTML)                            | 400 `Invalid JSON body.`                                   |
| body będące skalarem JSON (np. `5`)               | 400 `Missing required field: …`       | bez zmian — 400 `Missing required field: …` (serializer daje pusty obiekt) |
| `"currency": ["PLN"]` (nie-string)                | 500                                   | 400 `Invalid currency.`                                    |
| `"fromWalletId": "abc"` / `0` / `-1` / `1.5` / `true` | 404 `Wallet 0 not found.` lub rzutowanie | 400 `fromWalletId must be a positive integer.` (analogicznie `toWalletId`) |
| `"fromWalletId": 1.0` / `"012"` / `" 12"`          | 201 (rzutowanie `(int)`)              | 400 `fromWalletId must be a positive integer.`             |
| złe ID **i** zła kwota naraz                       | 400 `Amount must be a positive number.` | 400 `<field> must be a positive integer.` (kolejność pól)  |
| `/api/wallets/abc/deposit`, `/api/wallets/{>18 cyfr}/deposit` | 500                        | 404 `Not found.`                                           |
| `/api/wallets/-1/deposit`                          | 404 `Wallet -1 not found.`            | 404 `Not found.`                                           |
| brak tokena                                        | 401 HTML                              | 401 `{"error": "Unauthorized"}` (listener obejmuje błędy HTTP z firewalla) |
| inny błąd HTTP w `/api/*` (np. 405)                | HTML                                  | JSON `{"error": "<standardowy opis statusu>"}`             |

Identyfikatory portfeli w body: liczba całkowita JSON (`12`) lub ciąg cyfr (`"12"`), dodatnie.

## Komponenty

### DTO żądań — `src/Dto/Request/`

`CreateWalletRequest`, `TransferRequest`, `DepositRequest` — `final readonly`, publiczne pola typu `mixed`
(surowe wartości z JSON, żeby złe typy dawały nasze komunikaty zamiast błędów deserializacji), reguły
w atrybutach, typowane gettery używane przez kontroler po walidacji:

- `CreateWalletRequest::currency(): Currency`
- `TransferRequest::fromWalletId(): int`, `toWalletId(): int`, `amount(): string`
- `DepositRequest::amount(): string`

Reguły:

| Pole                          | Grupa `Presence`                                  | Grupa domyślna                                                                 |
|-------------------------------|---------------------------------------------------|--------------------------------------------------------------------------------|
| `currency`                    | `NotNull` → `Missing required field: currency.`   | `Choice` (wartości `Currency`) → `Invalid currency.`                            |
| `fromWalletId`, `toWalletId`  | `NotNull` → `Missing required field: {field}.`    | `Sequentially`: `Type(['integer','string'])`, `Regex('/^[1-9]\d*$/')` → `{field} must be a positive integer.` |
| `amount`                      | `NotNull` → `Missing required field: amount.`     | `PositiveAmount` → `Amount must be a positive number.`                          |

Kolejność „najpierw braki, potem formaty” przez `#[Assert\GroupSequence(['Presence', '<Klasa>'])]` na każdym DTO.

### Constraint `PositiveAmount` — `src/Validator/`

`PositiveAmount` + `PositiveAmountValidator`: wartość (string lub liczba JSON) po normalizacji do stringa musi
mieć format `Money::isValidFormat()` i być > 0 — ta sama logika co dzisiejsze `parsePositiveAmount()`
w kontrolerze. Normalizacja (`int|float` → `(string)`, string bez zmian, reszta → `null`) w jednym miejscu,
używana przez walidator i getter `amount()` DTO.

### Kontroler

Akcje przyjmują DTO przez `#[MapRequestPayload(acceptFormat: 'json', validationFailedStatusCode: 400)]`,
wołają serwis i zwracają odpowiedź — bez `json_decode`, bez `try/catch`, bez `parsePositiveAmount()`.
Trasa wpłaty: `requirements: ['id' => '\d+']`.

### `ApiExceptionListener` — `src/EventListener/`

Listener `kernel.exception`, działa tylko dla ścieżek zaczynających się od `/api`. Kolejność dopasowania:

1. **Wyjątki domenowe** (tabela w listenerze — wiedza o HTTP zostaje w warstwie HTTP, wyjątki domenowe bez zmian):

   | Wyjątek                          | Status |
   |----------------------------------|--------|
   | `WalletNotFoundException`        | 404    |
   | `WalletAlreadyExistsException`   | 409    |
   | `InvalidMoneyAmountException`    | 400    |
   | `DepositLimitExceededException`  | 400    |
   | `SameWalletTransferException`    | 400    |
   | `WalletBlockedException`         | 422    |
   | `InsufficientFundsException`     | 422    |

   Treść: `{"error": $exception->getMessage()}`.
2. `HttpException` z poprzednim `ValidationFailedException` → status wyjątku (400), komunikat pierwszego naruszenia.
3. `UnsupportedMediaTypeHttpException` → 415 `Unsupported content type, expected application/json.`
4. Pozostałe `HttpExceptionInterface` z `MapRequestPayload` o statusie 400 (nieczytelny JSON, puste body, skalar)
   → 400 `Invalid JSON body.`
5. `NotFoundHttpException` → 404 `Not found.`
6. Inne `HttpExceptionInterface` → jego status, komunikat = standardowy opis statusu (`Response::$statusTexts`).
7. Wszystko inne → bez zmian (domyślna obsługa Symfony).

Nagłówki z `HttpExceptionInterface::getHeaders()` (np. `Allow` przy 405) przenoszone do odpowiedzi.

## Testy

1. **Najpierw testy charakteryzujące** — `tests/Functional/` (`WebTestCase`, `symfony/browser-kit`), prawdziwe
   żądania HTTP z tokenem, baza `app_test` wycofywana po każdym teście. Pokrywają **każdy wiersz** tabeli
   „Kontrakt błędów” oraz odpowiedzi sukcesu (201/200 i ich JSON). Pisane i uruchamiane **przed** refaktorem
   (z `Content-Type: application/json`) — muszą przejść na obecnym kodzie i bez zmian po refaktorze.
2. Testy przypadków z tabeli „Zmiany zachowania” — pisane jako failujące przed implementacją (TDD).
3. Testy jednostkowe: walidacja każdego DTO (Validator z mapowaniem atrybutów — komunikat i kolejność),
   `PositiveAmountValidator`, `ApiExceptionListener` (każda gałąź, ścieżka spoza `/api` nietknięta).
4. `tests/Controller/WalletControllerTest.php` (wywołuje metody z ręcznym `Request`) — usunięty; jego przypadki
   przechodzą do testów funkcjonalnych z punktu 1.

## Dokumentacja

README: sekcja API — wymagany nagłówek `Content-Type: application/json`, format błędów `{"error": …}`.
`docs/business-logic.md` bez zmian (logika biznesowa się nie zmienia).

## Poza zakresem

- Domyślne zatwierdzanie fraud review w `app:process-transactions -n`.
- Aktualizacja podatnych zależności (`composer audit`).

## Kryteria sukcesu

- Testy charakteryzujące przechodzą przed i po refaktorze bez zmian w nich samych.
- Kontroler nie zawiera `json_decode`, `try/catch` ani ręcznej walidacji.
- Żaden przypadek z tabeli „Zmiany zachowania” nie zwraca 500.
- Pełny suite zielony.
