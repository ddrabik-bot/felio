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

This executes the Pest suite in the `app` Compose service. Build Vue assets when needed with `make frontend`.

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

## EOD market-data persistence

`MarketDataPersistenceService` persists only available `InstrumentMarketData`
results into PostgreSQL. An EOD snapshot is unique by canonical instrument,
provider, provider symbol, and session date. Daily OHLC rows are unique within
the snapshot by trading date. Both identities are written using PostgreSQL
native `INSERT ... ON CONFLICT` upserts, making provider retries idempotent.

Corporate-action values and OHLC values are stored as exact source strings, not
PHP floats. Each action has a SHA-256 identity derived from its type, source
date, raw value, and a provider event ID (or stable source-array position when
the provider has none), so even otherwise identical raw same-day events remain
distinct. Snapshot records retain the explicit retrieval timestamp, source
timezone, and provider version supplied by the adapter. Unavailable and no-data
instruments create no snapshot, OHLC, or action records. This boundary performs
no valuation, FX, XTB import, dashboard, scheduler, or provider fallback work.

Run the focused persistence checks against the Compose PostgreSQL service with:

```sh
make test-market-data-persistence
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
make test               Run the Pest suite in the app container.
make test-fx            Run deterministic NBP Table-A FX adapter tests.
make test-market-data-persistence Run focused EOD persistence tests on PostgreSQL.
make frontend         Install locked npm dependencies and build Vite assets.
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
