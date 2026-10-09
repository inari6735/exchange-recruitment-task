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
