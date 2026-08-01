#!/usr/bin/env python3
"""Live, isolated feasibility probe for Narodowy Bank Polski FX Table A."""

from __future__ import annotations

import argparse
import json
import platform
import sys
import time
import urllib.error
import urllib.request
from dataclasses import asdict, dataclass
from datetime import UTC, date, datetime
from decimal import Decimal, InvalidOperation
from enum import StrEnum
from pathlib import Path
from typing import Any, Callable
from zoneinfo import ZoneInfo

API_BASE_URL = "https://api.nbp.pl/api/exchangerates/rates"
API_CONTRACT = "unversioned"
PROVIDER_NAME = "Narodowy Bank Polski (NBP)"
SOURCE_TIMEZONE = "Europe/Warsaw"
TABLE = "A"
CURRENCIES = ("USD", "EUR")


class FxAvailability(StrEnum):
    AVAILABLE = "available"
    UNAVAILABLE = "unavailable"
    STALE = "stale"


@dataclass(frozen=True)
class NbpHttpResponse:
    status_code: int
    body: str
    headers: dict[str, str]


@dataclass(frozen=True)
class FxRateResult:
    currency: str
    requested_date: date
    effective_date: date | None
    availability: FxAvailability
    pln_per_unit: Decimal | None
    reason: str | None
    attempts: int
    retrieval_timestamp: str
    provider: str = PROVIDER_NAME
    provider_api_contract: str = API_CONTRACT
    source_timezone: str = SOURCE_TIMEZONE
    table: str = TABLE

    def to_json(self) -> dict[str, Any]:
        result = asdict(self)
        result["availability"] = self.availability.value
        result["requested_date"] = self.requested_date.isoformat()
        result["effective_date"] = self.effective_date.isoformat() if self.effective_date else None
        result["pln_per_unit"] = str(self.pln_per_unit) if self.pln_per_unit is not None else None
        return result


HttpGetter = Callable[[str, float], NbpHttpResponse]


def utc_now() -> str:
    return datetime.now(UTC).isoformat()


def request_url(currency: str, requested_date: date | None) -> str:
    currency = currency.upper()
    if requested_date is None:
        return f"{API_BASE_URL}/{TABLE}/{currency}/?format=json"
    day = requested_date.isoformat()
    return f"{API_BASE_URL}/{TABLE}/{currency}/{day}/{day}/?format=json"


def urllib_get(url: str, timeout_seconds: float) -> NbpHttpResponse:
    request = urllib.request.Request(url, headers={"Accept": "application/json", "User-Agent": "Felio-NBP-FX-spike/1.0"})
    try:
        with urllib.request.urlopen(request, timeout=timeout_seconds) as response:
            return NbpHttpResponse(
                status_code=response.status,
                body=response.read().decode("utf-8", errors="replace"),
                headers={key: value for key, value in response.headers.items()},
            )
    except urllib.error.HTTPError as error:
        return NbpHttpResponse(
            status_code=error.code,
            body=error.read().decode("utf-8", errors="replace"),
            headers={key: value for key, value in error.headers.items()} if error.headers else {},
        )


class NbpFxProbe:
    """Fetch NBP Table A rates without substituting missing or stale observations."""

    def __init__(
        self,
        getter: HttpGetter = urllib_get,
        sleep: Callable[[float], None] = time.sleep,
        max_attempts: int = 2,
        timeout_seconds: float = 15.0,
    ) -> None:
        self.getter = getter
        self.sleep = sleep
        self.max_attempts = max_attempts
        self.timeout_seconds = timeout_seconds

    def historical(self, currency: str, requested_date: date) -> FxRateResult:
        return self._fetch(currency, requested_date)

    def current(self, currency: str, as_of_date: date) -> FxRateResult:
        return self._fetch(currency, as_of_date, current=True)

    def _fetch(self, currency: str, requested_date: date, current: bool = False) -> FxRateResult:
        currency = currency.upper()
        retrieved_at = utc_now()
        url = request_url(currency, None if current else requested_date)
        last_reason = "retry_exhausted"

        for attempt in range(1, self.max_attempts + 1):
            try:
                response = self.getter(url, self.timeout_seconds)
            except (OSError, TimeoutError, urllib.error.URLError) as error:
                last_reason = f"transport_error:{type(error).__name__}"
                if attempt < self.max_attempts:
                    self.sleep(float(attempt))
                    continue
                return self._unavailable(currency, requested_date, last_reason, attempt, retrieved_at)

            if response.status_code == 404:
                return self._unavailable(currency, requested_date, "not_found", attempt, retrieved_at)
            if response.status_code in {408, 425, 429, 500, 502, 503, 504}:
                last_reason = f"http_{response.status_code}"
                if attempt < self.max_attempts:
                    self.sleep(self._retry_after(response.headers, attempt))
                    continue
                return self._unavailable(currency, requested_date, last_reason, attempt, retrieved_at)
            if not 200 <= response.status_code < 300:
                return self._unavailable(currency, requested_date, f"http_{response.status_code}", attempt, retrieved_at)

            return self._parse(currency, requested_date, response.body, attempt, retrieved_at)

        return self._unavailable(currency, requested_date, last_reason, self.max_attempts, retrieved_at)

    @staticmethod
    def _retry_after(headers: dict[str, str], attempt: int) -> float:
        value = headers.get("Retry-After") or headers.get("retry-after")
        try:
            return max(0.0, float(value)) if value is not None else float(attempt)
        except ValueError:
            return float(attempt)

    def _parse(
        self,
        currency: str,
        requested_date: date,
        body: str,
        attempts: int,
        retrieved_at: str,
    ) -> FxRateResult:
        try:
            payload = json.loads(body, parse_float=str)
        except json.JSONDecodeError:
            return self._unavailable(currency, requested_date, "invalid_json", attempts, retrieved_at)
        if not isinstance(payload, dict):
            return self._unavailable(currency, requested_date, "invalid_payload", attempts, retrieved_at)
        if payload.get("table") != TABLE:
            return self._unavailable(currency, requested_date, "unexpected_table", attempts, retrieved_at)
        if payload.get("code") != currency:
            return self._unavailable(currency, requested_date, "unexpected_currency", attempts, retrieved_at)
        rates = payload.get("rates")
        if not isinstance(rates, list) or len(rates) != 1 or not isinstance(rates[0], dict):
            return self._unavailable(currency, requested_date, "unexpected_rate_count", attempts, retrieved_at)

        rate = rates[0]
        try:
            effective_date = date.fromisoformat(str(rate["effectiveDate"]))
            mid = Decimal(str(rate["mid"]))
        except (KeyError, TypeError, ValueError, InvalidOperation):
            return self._unavailable(currency, requested_date, "invalid_rate_fields", attempts, retrieved_at)
        if not mid.is_finite() or mid <= 0:
            return self._unavailable(currency, requested_date, "invalid_mid", attempts, retrieved_at)

        if effective_date < requested_date:
            return self._result(currency, requested_date, effective_date, FxAvailability.STALE, mid, "effective_date_before_as_of_date", attempts, retrieved_at)
        if effective_date > requested_date:
            return self._result(currency, requested_date, effective_date, FxAvailability.UNAVAILABLE, None, "effective_date_after_requested_date", attempts, retrieved_at)
        return self._result(currency, requested_date, effective_date, FxAvailability.AVAILABLE, mid, None, attempts, retrieved_at)

    @staticmethod
    def _result(
        currency: str,
        requested_date: date,
        effective_date: date,
        availability: FxAvailability,
        mid: Decimal | None,
        reason: str | None,
        attempts: int,
        retrieved_at: str,
    ) -> FxRateResult:
        return FxRateResult(currency, requested_date, effective_date, availability, mid, reason, attempts, retrieved_at)

    @staticmethod
    def _unavailable(currency: str, requested_date: date, reason: str, attempts: int, retrieved_at: str) -> FxRateResult:
        return FxRateResult(currency, requested_date, None, FxAvailability.UNAVAILABLE, None, reason, attempts, retrieved_at)

    @staticmethod
    def markdown_report(payload: dict[str, Any]) -> str:
        provider = payload["provider"]
        lines = [
            "# NBP FX provider feasibility results",
            "",
            f"Generated: `{payload['generated_at']}`",
            f"Provider: `{provider['name']}`; API contract: `{provider['api_contract']}`; source timezone: `{payload['source_timezone']}`.",
            "",
            "| Scenario | Currency | Requested date | Effective date | Outcome | PLN per unit | Attempts | Reason |",
            "|---|---|---|---|---|---:|---:|---|",
        ]
        for scenario in payload["scenarios"]:
            for item in scenario["results"]:
                lines.append(
                    "| {scenario} | {currency} | {requested_date} | {effective_date} | {availability} | {pln_per_unit} | {attempts} | {reason} |".format(
                        scenario=scenario["name"],
                        currency=item["currency"],
                        requested_date=item["requested_date"],
                        effective_date=item["effective_date"] or "—",
                        availability=item["availability"],
                        pln_per_unit=item["pln_per_unit"] or "—",
                        attempts=item["attempts"],
                        reason=item["reason"] or "—",
                    )
                )
        return "\n".join(lines) + "\n"


def probe_currencies(probe: NbpFxProbe, currencies: tuple[str, ...], requested_date: date) -> list[FxRateResult]:
    """Isolate every currency so one provider error cannot cancel the batch."""
    results: list[FxRateResult] = []
    for currency in currencies:
        try:
            results.append(probe.historical(currency, requested_date))
        except Exception as error:  # Defensive batch boundary for probe faults.
            results.append(probe._unavailable(currency, requested_date, f"probe_error:{type(error).__name__}", 0, utc_now()))
    return results


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--historical-date", type=date.fromisoformat, default=date(2026, 7, 31))
    parser.add_argument("--weekend-date", type=date.fromisoformat, default=date(2026, 8, 1))
    parser.add_argument("--output", type=Path, default=Path("results/nbp-fx.json"))
    args = parser.parse_args()

    now_in_warsaw = datetime.now(ZoneInfo(SOURCE_TIMEZONE)).date()
    probe = NbpFxProbe()
    scenarios = [
        {"name": "historical_exact", "results": [item.to_json() for item in probe_currencies(probe, CURRENCIES, args.historical_date)]},
        {"name": "weekend_exact", "results": [item.to_json() for item in probe_currencies(probe, CURRENCIES, args.weekend_date)]},
        {"name": "current_latest", "results": [probe.current(currency, now_in_warsaw).to_json() for currency in CURRENCIES]},
    ]
    payload = {
        "generated_at": utc_now(),
        "source_timezone": SOURCE_TIMEZONE,
        "provider": {"name": PROVIDER_NAME, "api_base_url": API_BASE_URL, "api_contract": API_CONTRACT, "table": TABLE},
        "environment": {"python": sys.version, "platform": platform.platform()},
        "scenarios": scenarios,
        "limitations": [
            "NBP Table A publishes PLN mid rates per one foreign-currency unit. It is not a bid/ask execution quote.",
            "Historical requests are exact-date requests; a 404 is unavailable and is never replaced with a neighbouring business-day rate.",
            "The current endpoint may return the last published business-day rate. It is classified stale whenever its effectiveDate predates the Warsaw as-of date.",
            "The NBP endpoint does not expose a semantic API version. Persist the literal api base URL, table, source timezone, effective date, retrieval instant, and a configured provider implementation version.",
        ],
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n")
    args.output.with_suffix(".md").write_text(NbpFxProbe.markdown_report(payload))
    print(json.dumps({"output": str(args.output), "scenarios": len(scenarios), "currencies": len(CURRENCIES)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
