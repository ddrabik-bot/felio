# NBP FX provider feasibility spike

## Question and scope

Can Felio use the public Narodowy Bank Polski (NBP) Web API as an explicit
source of end-of-day (EOD) PLN mid-rate snapshots for USD and EUR, without
silently filling missing dates or one-currency failures?

This is a research-only, disposable probe. It is independent of Laravel and
contains no portfolio valuation, FX persistence, XTB import, dashboard,
scheduler, or provider fallback logic.

## Reproduce

```sh
make test-nbp-fx-spike
make spike-nbp-fx
```

The probe uses a dedicated, digest-pinned Python container on the existing
isolated `felio_felio_net`. `make spike-nbp-fx` gives the one-off container a
unique name, copies JSON and Markdown evidence to a temporary host directory,
checks both files are non-empty, publishes them under `results/`, then removes
the container. The live result files are intentionally ignored because API
responses and retrieval instants are time-dependent.

The probe has no external Python dependencies. Its HTTP requests use HTTPS,
`Accept: application/json`, a 15-second timeout, and at most two attempts. It
retries only network errors and 408, 425, 429, 500, 502, 503, and 504; a 404,
malformed JSON, unexpected table/currency, and invalid rate are terminal per
currency. `Retry-After` is honored when it is numeric, otherwise the single
retry waits one second. This is a bounded availability measure, not proof of
an NBP throughput quota.

## API semantics verified

NBP's API documentation at `https://api.nbp.pl/en.html` describes:

- table **A** and **B** as `mid` (average) rates; table **C** as `bid`/`ask`;
- `effectiveDate` as the publication date;
- exact ISO-8601 date routes and a `404` when data have not been published;
- "current" as the last released data at request time;
- history from 2002-01-02 and a maximum 93-day interval per range query.

For a PLN valuation rate, select **Table A** and preserve its `mid` exactly as
a decimal string. The API values are PLN per one foreign-currency unit:

```text
USD -> PLN:  USD amount * USD Table-A mid
EUR -> PLN:  EUR amount * EUR Table-A mid
PLN -> USD/EUR: divide by the corresponding mid using decimal arithmetic
```

Table C is appropriate only if a later, explicitly selected policy requires
NBP's calculated bid/ask, not an EOD mid valuation. Table B should not be used
for USD/EUR: its current USD endpoint returned a historic B-table observation
in the live investigation, while Table A returned the current daily USD rate.

For an EOD snapshot, call the **exact-date** endpoint, not `current`:

```text
https://api.nbp.pl/api/exchangerates/rates/A/USD/YYYY-MM-DD/YYYY-MM-DD/?format=json
https://api.nbp.pl/api/exchangerates/rates/A/EUR/YYYY-MM-DD/YYYY-MM-DD/?format=json
```

The probe deliberately uses the same start and end date so the result must be
one observation. It rejects a wrong table, wrong code, multiple rates, invalid
mid, or a future effective date. It never replaces a missing weekend/holiday
with Friday's rate.

## Live evidence

`make spike-nbp-fx` completed at `2026-08-01T22:27:49Z` (the generated result
records the exact runtime timestamp). It observed:

| Scenario | USD | EUR | Interpretation |
|---|---|---|---|
| Exact historical date `2026-07-31` | `3.7425`, effective `2026-07-31` | `4.3128`, effective `2026-07-31` | Available Table-A historical values. |
| Exact weekend date `2026-08-01` | HTTP 404 -> unavailable | HTTP 404 -> unavailable | No silent prior-business-day substitution. |
| Current endpoint on the local Warsaw calendar day | returned `2026-07-31` | returned `2026-07-31` | Classified stale because the effective date predates the Warsaw as-of date. |

The detailed point-in-time evidence is generated in
`results/nbp-fx.json` and `results/nbp-fx.md`. It is not committed.

## Typed outcome and batch isolation

`probe.py` defines an immutable `FxRateResult` for every requested currency:

```text
currency, requested_date, effective_date, availability,
pln_per_unit: Decimal | None, reason, attempts, retrieval_timestamp,
provider, provider_api_contract, source_timezone, table
```

`availability` is exactly one of:

- `available`: exact requested `effectiveDate` and a valid positive `mid`;
- `unavailable`: no usable rate, including exact-date 404, malformed responses,
  terminal HTTP errors, or exhausted bounded retry;
- `stale`: a current-endpoint response whose `effectiveDate` is before the
  requested Warsaw as-of date. It retains the observed rate only for diagnosis;
  it is not an acceptable EOD value for that date.

`probe_currencies()` has a per-currency defensive boundary. The unit suite
proves a retried USD 503 cannot prevent a succeeding EUR result. It also proves
exact decimal preservation, weekend 404 behavior, current-rate staleness,
429 retry behavior, malformed response handling, and table validation.

## Publication delay and calendar policy

The API does not publish a service-level time of day or rate-limit contract.
The documentation's `/today/` examples explicitly return 404 when today's
table has not yet been published. Therefore a production design must treat a
same-day request before publication as **unavailable/pending**, not as an
invitation to query `current` and label a prior day's rate as today's EOD rate.

Weekends, Polish banking holidays, and publication lag are all represented as
no exact observation for the requested publication date. A future business
calendar policy may explicitly choose a prior business-day EOD rate, but that
would be a caller-owned valuation rule with the selected effective date stored;
it is not a provider fallback and is outside this spike.

## Metadata required for a later persistence boundary

For every accepted or diagnosed response retain:

1. Felio provider identifier and implementation version (for example,
   `nbp-table-a-v1`), plus literal API base URL.
2. Table `A`, ISO currency code, requested publication date, response table
   number (`no`) when added to the production adapter, and `effectiveDate`.
3. Exact source decimal string and scale for `mid`; do not convert to binary
   float.
4. Retrieval timestamp in UTC, provider HTTP `Date` header if received, and
   source calendar timezone `Europe/Warsaw`.
5. Typed availability/staleness outcome, reason, attempts, and safe HTTP/error
   diagnostics.

NBP's endpoint does not expose a semantic API version; this spike records the
contract as `unversioned`. A production adapter must version its own provider
implementation/configuration and preserve the endpoint/table metadata rather
than inventing an upstream version.

## Verdict: VALIDATED WITH OPERATING CONSTRAINTS

NBP Table A is suitable as a public-source provider for **historical published
EOD PLN mid-rate snapshots** for USD and EUR, provided Felio requests an exact
publication date, stores a decimal mid with the metadata above, and makes
unavailable/stale outcomes visible.

It is not suitable for pretending that every calendar day has a rate, for
intraday execution pricing, or for a silent fallback. The public API has no
observed/advertised rate-limit SLA in this spike, so production use needs a
small bounded retry policy, monitoring, and an explicit product decision if a
rate is missing or stale.
