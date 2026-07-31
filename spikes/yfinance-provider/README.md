# yfinance provider feasibility spike

## Question and scope

Given the sample XTB symbols, can an isolated `yfinance` client resolve each
instrument and obtain daily history, dividend events, split events, exchange,
and currency before Felio implements a market-data domain?

This is a non-production experiment. `probe.py` is deliberately independent of
Laravel and contains no persistence, instrument, price, FX, or valuation logic.
It requests each symbol separately and records exceptions per symbol, so one
Yahoo Finance outage or mapping error cannot discard the remaining observations.

## Reproduce

```sh
make spike-yfinance
```

The target builds an image from a digest-pinned Python base with
`yfinance==0.2.66`, runs it in the isolated
`felio_felio_net`, writes both report files inside the one-off container, copies
both to a temporary host directory, verifies they are non-empty, then publishes
them to `results/`. The target assigns the one-off container a unique name and
removes it with `docker rm` before it succeeds; a failed copy or cleanup makes
the target fail. The result files are ignored because Yahoo Finance responses
are live and time-dependent.

The test uses `period=max`, `interval=1d`, `actions=True`, and
`auto_adjust=False`. It defines recognition as a non-empty daily history. Each
history request has a 15-second timeout and one retry after two seconds.
When history is available, metadata and fast-info failures are captured
separately. If history is unavailable, its errors and unavailable status are
recorded for that symbol.

## Live result

Run: 2026-07-31 13:34 UTC, Python 3.13.14, yfinance 0.2.66.

| XTB input | Yahoo provider symbol | Recognized | Exchange / currency | History (daily rows) | Splits | Dividends | Probe errors |
|---|---|---:|---|---|---:|---:|---:|
| OTLK.US | OTLK | yes | NCM / USD | 2016-06-14–2026-07-31 (2,547) | 2 | 0 | 0 |
| PZU.PL | PZU.WA | yes | WSE / PLN | 2010-05-11–2026-07-31 (4,156) | 1 | 14 | 0 |
| PEO.PL | PEO.WA | yes | WSE / PLN | 2000-01-03–2026-07-31 (6,834) | 0 | 23 | 0 |
| PKN.PL | PKN.WA | yes | WSE / PLN | 2000-01-03–2026-07-31 (6,834) | 0 | 20 | 0 |
| XTB.PL | XTB.WA | yes | WSE / PLN | 2016-05-06–2026-07-31 (2,597) | 0 | 10 | 0 |
| IUSQ.DE | IUSQ.DE | yes | GER / EUR | 2011-10-21–2026-07-31 (3,748) | 0 | 0 | 0 |
| EXIE.DE | EXIE.DE | yes | GER / EUR | 2023-02-27–2026-07-31 (869) | 0 | 0 | 0 |
| VBTC.DE | VBTC.DE | yes | GER / EUR | 2020-11-19–2026-07-31 (1,448) | 0 | 0 | 0 |

All eight mappings returned a non-empty history and no probe-level error in
this run. The yfinance/Pandas dependency emitted deprecation warnings about
`Timestamp.utcnow`; they did not prevent any request or alter the recorded
payload. Full live evidence is in the locally generated JSON, including the
last ten dividend/split events per symbol.

## Recommended initial mapping

Use an explicit provider-symbol mapping owned by a future
`YahooFinanceMarketDataProvider`, never derive a canonical Felio instrument
identity solely from the Yahoo string:

```text
OTLK.US -> OTLK       (NasdaqCM, USD)
*.PL    -> *.WA       (Warsaw, PLN)
*.DE    -> *.DE       (Xetra/GER, EUR)
PKN.PL  -> PKN.WA     (legacy input retained for compatibility)
```

The mapping record should retain: Felio's canonical instrument ID, provider
name, resolved provider symbol, provider exchange, quote currency, retrieval
instant, and source time zone. Before a production import, validate the
provider's name/ISIN/exchange against the broker instrument because suffixes
are provider conventions. Treat `PKN.PL` as an explicitly versioned legacy
mapping; do not assume a renamed issuer remains discoverable under every
historical alias indefinitely.

Request unadjusted OHLC plus actions as the raw source. A later price-normalizer
must make an explicit choice between raw close and adjusted close, and process
splits/dividends deterministically. This spike proves that events are supplied
for OTLK and PZU, not that their corporate-action data is complete enough for
accounting.

## Availability and fallback recommendation

**Verdict: PARTIAL — suitable only as an initial, best-effort EOD research
provider.** The sample coverage is good, but yfinance is an unofficial client
of Yahoo Finance with no SLA, published quota, completeness guarantee, or
contractual support. Yahoo can throttle or change endpoints without notice.
The single successful run is not a rate-limit benchmark.

Initial provider behavior should be:

1. Resolve the provider mapping and fetch one instrument independently.
2. Retry transient transport/rate-limit failures with bounded exponential
   backoff; do not retry invalid mappings indefinitely.
3. Return a typed per-instrument `Unavailable` / `NoData` result containing
   the provider, resolved symbol, and safe diagnostic. Continue a batch for
   every other instrument.
4. Cache successful immutable EOD responses with retrieval metadata. Do not
   silently substitute another provider's price, currency, or corporate action
   for the same valuation run.
5. When accuracy, timeliness, or service assurance matters, configure an
   explicit licensed/provider-or-broker fallback. If the fallback is absent or
   fails, mark that instrument's valuation unavailable/stale rather than
   inventing a value. FX needs its own provider and validation spike; it is not
   implied by the per-instrument quote currency reported here.

This makes one unavailable ticker observable and isolated while avoiding a
silent mix of incompatible market-data sources.
