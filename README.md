# Felio

Felio is a Laravel application scaffold. This bootstrap intentionally contains no portfolio-domain logic.

## Stack

- PHP 8.3 (in the application image)
- Laravel 13.23.0
- Vue 3.5.40, Inertia Vue 3.6.1, and Vite 8.2.0
- Pest 4.7.5
- Nginx 1.27
- PostgreSQL 17
- Node.js 22 (containerized build tooling)

Exact Composer and npm dependency versions are locked in `composer.lock` and `package-lock.json`.

## Prerequisites

The host needs only:

- Docker Engine with the Docker Compose v2 plugin (`docker compose`)
- GNU Make
- `curl` for the optional HTTP health checks
- the pre-existing external Docker network `cloudflare_tunnel`

PHP, Composer, Node.js, and PostgreSQL are not installed or run on the host. They run in the project's Compose services and isolated Docker volumes.

Felio owns the bridge network `felio_felio_net` and the volumes `felio_postgres_data`, `felio_vendor`, and `felio_node_modules`. Compose attaches only the `web` service to the pre-existing `cloudflare_tunnel` network; it does not create or remove that network.

## Local development

Felio publishes its web service on host port **8086**. This port is recorded in `docker-compose.yml`, `Makefile`, and this document. Before starting the project, confirm that the port is free:

```sh
docker ps --format '{{.Ports}}' | grep -oP '\d+(?=->)' | sort -n
```

### Start the stack

```sh
make build
make up
make ps
curl -i http://localhost:8086/
curl -i http://localhost:8086/health
```

The `/` and `/health` endpoints return HTTP 200 when the web and application services are healthy.

### Apply migrations

After the database is running, apply pending Laravel migrations:

```sh
make migrate
```

For a disposable local database, this destructive smoke check recreates the migration schema and prints the migration status:

```sh
make smoke-migrations
```

### Run tests

```sh
make test
```

This runs the complete Pest suite against a unique disposable PostgreSQL Compose project and volume through `phpunit.pgsql.xml`; it does not touch the development database. Build Vue assets when needed with `make frontend`.

## Session authentication

Public session authentication is available through Inertia pages at `/register`, `/login`, and `/forgot-password`. Registration creates a password-hashed user, starts a regenerated session, and redirects to `/portfolio/onboarding`, where the authenticated user creates their active XTB account before visiting the import or valuation pages. The onboarding endpoint scopes the created account to the authenticated user and does not expose other users' portfolios. Login and logout use Laravel's `web` session guard. Password reset links use Laravel's reset-token broker and are delivered through the configured mailer (the test environment uses Laravel's array mailer). Authentication does not persist uploaded workbooks or alter the existing portfolio-import workflow.

Run focused authentication coverage against an isolated disposable PostgreSQL Compose project with:

```sh
make test-auth
```

## Yahoo Finance market-data adapter

`YahooFinanceMarketDataProvider` is the first concrete implementation of the
`MarketDataProvider` contract. It receives an explicit
`ProviderInstrumentMapping`, calls only its injected `YahooFinanceGateway` with
the resolved provider symbol, and returns one `InstrumentMarketData` result per
instrument. Its payload preserves unadjusted daily OHLC, provider-reported
exchange and quote currency, dividends, and splits. No valuation, FX conversion,
XTB import, persistence, or provider fallback is performed here.

The adapter treats gateway transport and rate-limit failures as retryable under
`MarketDataRetryPolicy`; it classifies empty history as typed `NoData` and
malformed/provider failures as `Unavailable`. `fetchMany()` processes every
mapping independently, so a failure for one resolved symbol does not discard
other results. The gateway is deliberately a separate boundary: deterministic
Pest tests use fakes, while the time-dependent live yfinance evidence remains
an explicit `make spike-yfinance` command.

## NBP Table-A FX adapter

`NbpTableAFxProvider` is the production-facing contract and adapter for NBP
Table-A PLN mid rates. `historical()` always uses the exact same start/end date
route and accepts a rate only when its `effectiveDate` equals the requested
Warsaw calendar date. A weekend, Polish banking holiday, or publication-delay
`404` is an explicit `Unavailable` result; the adapter never queries or
substitutes a previous business day.

`current()` is diagnostic only: it can return `Stale` when NBP's latest rate is
older than the supplied `Europe/Warsaw` as-of date, and such a result is not an
accepted EOD rate. Both calls return typed per-currency results that preserve
the source `mid` as a decimal string and retain retrieval time, requested API
endpoint, table number, effective date, source timezone, upstream contract,
and Felio implementation version. Transient network and selected HTTP failures
are retried within the explicit `NbpFxRetryPolicy` bound. `historicalMany()`
processes every currency independently, without a fallback provider or a
silent date substitution.

`NbpFxGateway` is the testable HTTP boundary; `LaravelNbpFxGateway` is its
Laravel HTTP-client implementation. Deterministic provider tests use gateway
fakes and can be run with:

```sh
make test-fx
```

This adapter performs no FX persistence, portfolio valuation, XTB import,
dashboard, scheduler, or fallback work.

## Valuation foundation

`App\Domain\Valuation\ValuationService` is a pure in-memory domain service. Its
`ValuationInput`, `PriceQuote`, and `ValuationResult` value objects retain every
source number as a decimal string and use PHP BCMath only; PHP floats are not
used. Quantity, price, and supplied non-null PLN-per-unit FX values must be
strictly positive decimal strings. USD, EUR, and other foreign-currency prices
require a matching `FxRateResult`; a stale rate is accepted only under the explicit
stale-rate policy. PLN prices require no FX result.

The service preserves the full decimal scale produced by quantity × price ×
PLN-per-unit and performs one `ROUND_HALF_UP` equivalent only at the final PLN
grosz boundary. It returns `available`, `stale`, or `unavailable`, never inventing a
PLN amount if the price or required FX rate is unavailable or missing. Default
`StaleFxRatePolicy::Reject` turns a stale FX rate into an unavailable valuation;
`StaleFxRatePolicy::Accept` permits the value but returns it as `stale`. Both
paths carry explicit diagnostics, including the provider's stale/unavailable
reason. A stale price is similarly retained as `stale` when its FX requirement
is satisfied.

This foundation does not persist valuations, aggregate portfolios, import XTB,
run a scheduler, render a dashboard, or use a provider fallback. Run its
deterministic coverage with:

```sh
make test-valuation
```

## FX rate snapshots persistence

`FxRatePersistenceService` persists every `FxRateResult` returned by an FX
provider, including `available`, `stale`, and `unavailable` results. It stores
currency, requested and effective Warsaw dates, exact PLN-per-unit source
strings as PostgreSQL `NUMERIC`, availability, reason, attempts, retrieval
time, provider implementation version, endpoint, table, source timezone, and
JSON provider-response metadata (`table_number` and API contract). It never
manufactures a rate or substitutes a prior business day.

The database-native `INSERT ... ON CONFLICT` identity is provider implementation
version + currency + requested date + a SHA-256 source-observation identity. The
source-observation identity includes effective date (or its explicit absence),
availability, endpoint, table, source timezone, and provider-response metadata.
Thus retrying the same source observation is idempotent, while a distinct
effective-date or source observation remains separately observable.

Run the focused PostgreSQL persistence checks with:

```sh
make test-fx-persistence
```

## EOD market-data persistence

`MarketDataPersistenceService` persists only available `InstrumentMarketData`
results into PostgreSQL. An EOD snapshot is unique by canonical instrument,
provider, provider symbol, and session date. Daily OHLC rows are unique within
the snapshot by trading date. Both identities are written using PostgreSQL
native `INSERT ... ON CONFLICT` upserts, making provider retries idempotent.

Corporate-action values and OHLC values enter persistence as exact decimal
strings, never PHP floats. SQLite keeps those values as `TEXT` in tests, so it
retains their lexical representation. PostgreSQL converts the production columns
to unconstrained `NUMERIC`: the numeric value remains exact, but PostgreSQL does
not retain lexical formatting such as trailing zeroes. Identity construction uses
the input decimal strings before persistence, not a value read back from a
PostgreSQL `NUMERIC` column. Each action has a SHA-256 identity derived from its
type, source date, and provider event ID when present. Without a provider ID,
the identity uses a canonical exact-decimal representation (string normalization
only: no float conversion) plus a deterministic occurrence within the
identical-event group, so equivalent representations such as `0.25` and `0.250`
are idempotent while otherwise distinct same-day events remain distinct.
Snapshot records retain the explicit retrieval timestamp, source
timezone, and provider version supplied by the adapter. Unavailable and no-data
instruments create no snapshot, OHLC, or action records. This boundary performs
no valuation, FX, XTB import, dashboard, scheduler, or provider fallback work.

Run the focused persistence checks against the Compose PostgreSQL service with:

```sh
make test-market-data-persistence
```

## Portfolio position and import boundary

`PortfolioImportService` is a persistence boundary for a future XTB parser; it
intentionally does not parse XLSX. A caller supplies a typed
`CanonicalInstrument`, an exact decimal-string quantity, optional average cost
in integer PLN grosze, an as-of timestamp, raw source values, and either a
`valid` or `rejected` row status. Raw values reject PHP floats, while rejected
rows retain raw JSON and a required diagnostic but can never create or alter a
normalized position.

The schema separates broker/account, import batch, source rows, and normalized
positions. A position is unique by account plus canonical instrument; its
latest valid source row carries the batch provenance. Batch identity is scoped
to the account and source-row identity is scoped to the batch. Database-native
`INSERT ... ON CONFLICT` upserts make a retry of an identical batch or source
row idempotent, including concurrent PostgreSQL writers. Callers can derive a
stable SHA-256 row identity with `PortfolioImportRow::deterministicIdentity()`;
that identity combines a stable source-row reference with canonicalized raw
source values, so duplicate-looking source rows do not collapse; it contains no
float conversion or instrument fallback.

Run the focused PostgreSQL persistence checks with:

```sh
make test-portfolio-persistence
```

## Portfolio valuation read model

`PortfolioValuationService` reads immutable valid import source rows as historical position snapshots: for every account and canonical instrument it selects the latest `as_of` at or before the explicit valuation date, then deterministic batch and source-row tie-breakers. It never treats the mutable `portfolio_positions` projection as historical truth. The read model selects only exact-date market OHLC and FX requested-date observations, rejects ambiguous observations, applies the existing stale-FX policy, and carries unavailable diagnostics without fallback. Available per-position PLN values and portfolio totals use integer grosze; checked accumulation rejects a total outside the PHP integer range.

Run its focused PostgreSQL coverage with:

```sh
make test-portfolio-valuation
```

No XLSX parsing, market valuation, FX conversion, scheduler, or background importing is implemented by this boundary.

## XTB XLSX import adapter

`XtbXlsxParser` reads only local XLSX files and recognizes the XTB `Cash Operations`
and `Closed Positions` worksheets. It validates matching account/product metadata,
converts Excel serial timestamps to UTC without floating-point arithmetic, retains
source decimals as strings, and produces a deterministic source reference per sheet
row. Only the case-sensitive operation values `Stock purchase` and `Stock sale` map to
`buy` and `sell`; every other cash operation is rejected. Valid mapped cash rows require
a timestamp, symbol, positive volume, and positive price. If either direct `Volume` or
`Price` is absent, the parser accepts only these fully anchored, ASCII-exact
`Comment` forms. It preserves raw cell text before validating `Operation` and
`Comment`, so leading or trailing ASCII spaces, tabs, and newlines are rejected:
`Quantity: <positive-decimal>; Price: <positive-decimal>` or
`STOCK BUY|SELL <positive-decimal> @ <positive-decimal>`. There is exactly one
space at each documented boundary; labels and the `STOCK` operation are case-sensitive.
The comment supplies only absent direct fields; complete direct fields are never
overridden or invalidated by an unsupported comment. Comments with additional text,
prefixes, duplicate labels, alternate whitespace, comma separators, or malformed/
non-positive numbers are rejected with
`invalid_or_missing_labeled_trade_comment`. Unsupported cash operations, closed
positions, missing fields, and invalid fields remain rejected rows with explicit diagnostics.

`XtbPortfolioImportAdapter` is the only adapter that feeds the existing import
boundary. It maps a valid cash row only when the caller supplies an exact
source-symbol to `CanonicalInstrument` mapping; it never guesses a provider symbol
or canonical instrument. The local SHA-256 workbook identity and deterministic row
identity make a repeated import idempotent. The committed XLSX test fixture is fully
synthetic and contains no production account, transaction, or comment data.

Run its PostgreSQL-backed coverage with:

```sh
make test-xtb-import
```

## XTB manual import workflow

`POST /portfolio/imports/xtb` accepts only a local `.xlsx` upload, writes it only to private temporary storage, parses it without database persistence, and returns a `READY_FOR_CONFIRMATION` preview with `valid`, `pending`, and `rejected` aggregate diagnostics. The temporary workbook is deleted after confirmation or parsing failure; it is neither logged nor stored in PostgreSQL.

`POST /portfolio/imports/xtb/{importId}/confirm` requires an explicit `{sourceSymbol: canonicalInstrument}` mapping payload before it processes the preview into the active XTB account. Rows with an unresolved but otherwise valid symbol are persisted as `pending`, while malformed/unsupported rows remain `rejected`; both preserve source sheet, row reference, and sanitized source values only after confirmation. Confirmation processing is idempotent through the workbook and source-row identities. The exposed workflow statuses are `UPLOADED`, `ANALYZING`, `READY_FOR_CONFIRMATION`, `CONFIRMED`, `PROCESSING`, `COMPLETED`, `COMPLETED_WITH_WARNINGS`, and `FAILED`; every confirmation-processing failure returns the complete lifecycle ending in `FAILED`, while persisted batches retain their completed status.

`POST /portfolio/import-batches/{batchId}/reprocess` applies explicit mappings to pending rows already retained in a batch, so resolving them does not require another workbook upload. `DELETE /portfolio/import-batches/{batchId}` is batch-wide only: it records the earliest affected source timestamp in `portfolio_import_recalculation_boundaries`, removes the batch, and rebuilds the account's normalized position projection from the remaining valid rows. No automatic XTB sync, scheduler, dividend import, mapping guesswork, or valuation behavior is added.

Run the focused Docker/PostgreSQL workflow test with:

```sh
make test-xtb-manual-import
```

This target creates a unique Compose project and PostgreSQL volume for each invocation, runs `migrate:fresh` only inside that disposable database, then removes the test project and volume on exit. It uses the standalone `docker-compose.test.yml`, which defines only the `app` and `db` services on a project-private bridge network; `make test-compose-isolation` inspects the resolved configuration and fails if `cloudflare_tunnel` or any external network is present. Pest is run with `phpunit.pgsql.xml`, and `XtbImportDatabaseStateTest` asserts `DB::getDriverName() === 'pgsql'`; a SQLite fallback therefore fails the focused command instead of silently passing. It never touches the development database.

## Portfolio valuation dashboard

`GET /portfolio/valuation?date=YYYY-MM-DD` is an authenticated local Inertia dashboard over the existing portfolio valuation read model. It scopes every valuation read to the authenticated user's active portfolio account, so positions from other accounts or users are excluded. The `date` query parameter is required and is the exact valuation date; it never defaults to the current date. The backend supplies the integer PLN-grosze total, deterministic position rows, source price metadata, FX status, valuation availability, and all diagnostics. The Vue page only renders these values; it does not calculate money, select source data, or omit unavailable positions.

The page renders accessible loading, empty, error, and tabular ready states. It displays unavailable price/value cells and stale or unavailable FX diagnostics explicitly. Build the client assets through the app image and run the focused route/view-model coverage with:

```sh
make frontend
make test-portfolio-dashboard
```

### Operate and stop the stack

```sh
make restart  # restart running services
make logs     # follow service logs (Ctrl-C stops log following)
make down     # stop and remove Felio services and its default network
```

`make down` intentionally preserves the named Docker volumes. Remove them explicitly only when a local reset is required.

## Make targets

```text
make up               Start the Compose services in the background.
make down             Stop and remove the Compose services.
make restart          Restart the running Compose services.
make logs             Follow Compose service logs.
make build            Rebuild Compose images.
make ps               Show Compose service status.
make test               Run the complete suite in a disposable PostgreSQL Compose project.
make test-postgresql    Run the complete suite in a disposable PostgreSQL Compose project.
make test-auth          Run registration, session login/logout, and password reset coverage in disposable PostgreSQL.
make test-fx            Run deterministic NBP Table-A FX adapter tests.
make test-valuation     Run deterministic valuation arithmetic and availability tests.
make test-fx-persistence Run focused FX snapshot persistence tests on PostgreSQL.
make test-market-data-persistence Run focused EOD persistence tests on PostgreSQL.
make test-portfolio-persistence Run focused portfolio import and position checks on PostgreSQL.
make test-portfolio-valuation Run focused historical portfolio valuation read-model checks on PostgreSQL.
make test-portfolio-dashboard Run focused Inertia dashboard route/view-model checks on PostgreSQL.
make test-xtb-import       Run focused XTB parser/adapter persistence tests on PostgreSQL.
make test-xtb-manual-import Run confirmation, pending reprocessing, and deletion-boundary tests on PostgreSQL.
make frontend         Build the Vite frontend in the application image.
make migrate          Apply pending Laravel migrations.
make smoke-migrations Recreate and verify the baseline PostgreSQL migrations.
make spike-yfinance    Run the isolated, live yfinance feasibility probe.
make test-nbp-fx-spike Run deterministic NBP FX probe tests in the spike container.
make spike-nbp-fx      Run the isolated, live NBP USD/EUR Table-A FX probe.
```

All targets invoke `docker compose` through the `COMPOSE` Make variable. Set it only when an alternative compatible Compose command is necessary, for example `make ps COMPOSE='docker compose --ansi never'`.

## yfinance feasibility spike

`spikes/yfinance-provider/` is a disposable, non-production experiment for the
initial provider decision. It probes the sample XTB instruments individually,
so a Yahoo Finance failure for one symbol never prevents the other probes.

```sh
make spike-yfinance
```

The command builds a dedicated Python container, copies its ignored,
point-in-time JSON result plus Markdown table to
`spikes/yfinance-provider/results/`, and removes the one-off container. Read
`spikes/yfinance-provider/README.md` for the mapping recommendation and the
provider limitations discovered by the spike.

## NBP FX feasibility spike

`spikes/nbp-fx-provider/` is an isolated, non-production probe of NBP Table-A
PLN mid rates for USD and EUR. It issues exact-date historical requests,
separately records missing dates and stale current responses, and preserves
rates as decimal strings. It does not perform valuation, persistence, imports,
or fallback.

```sh
make test-nbp-fx-spike
make spike-nbp-fx
```

The live probe output is ignored because it is time-dependent. Read
`spikes/nbp-fx-provider/README.md` for the exact-date policy, retry/error
classification, publication-delay constraints, required metadata, and verdict.

## Fork workflow

The development profile works only in the fork. Configure the remotes as follows:

```text
origin   https://github.com/ddrabik-bot/felio.git  (development fork; fetch and push)
upstream https://github.com/DanielDrabik/felio.git (Daniel's source repository; read-only)
```

`master` is the stable base branch in the fork. `dev` is the integration branch in the fork. Every task branch is created from the latest `origin/dev` using the `feat/<short-task-name>` convention (or the project's equivalent convention), and is pushed only to `origin`.

```sh
git fetch origin
git checkout dev
git pull --ff-only origin dev
git checkout -b feat/<short-task-name>
```

Never push to `upstream`, create branches there, or merge there. After all work in an approved stage has been verified and integrated into the fork's `dev` branch, the project opens one aggregate pull request from `ddrabik-bot:dev` to `DanielDrabik:master`. Individual task branches do not receive pull requests to the source repository.
