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
make test             Run the Pest suite in the app container.
make frontend         Install locked npm dependencies and build Vite assets.
make migrate          Apply pending Laravel migrations.
make smoke-migrations Recreate and verify the baseline PostgreSQL migrations.
make spike-yfinance    Run the isolated, live yfinance feasibility probe.
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
