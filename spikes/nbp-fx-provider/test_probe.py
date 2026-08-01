from __future__ import annotations

import json
import unittest
from datetime import UTC, date, datetime
from decimal import Decimal
from pathlib import Path
from unittest.mock import patch

from probe import FxAvailability, NbpFxProbe, NbpHttpResponse, probe_currencies


class ScriptedGetter:
    def __init__(self, outcomes: list[object]) -> None:
        self.outcomes = list(outcomes)
        self.urls: list[str] = []

    def __call__(self, url: str, timeout_seconds: float) -> NbpHttpResponse:
        self.urls.append(url)
        outcome = self.outcomes.pop(0)
        if isinstance(outcome, BaseException):
            raise outcome
        return outcome


def response(status: int, payload: object, headers: dict[str, str] | None = None) -> NbpHttpResponse:
    body = payload if isinstance(payload, str) else json.dumps(payload)
    return NbpHttpResponse(status_code=status, body=body, headers=headers or {})


def rate_payload(code: str, effective_date: str, mid: str = "3.7425") -> dict[str, object]:
    return {
        "table": "A",
        "currency": code,
        "code": code,
        "rates": [{"no": "147/A/NBP/2026", "effectiveDate": effective_date, "mid": mid}],
    }


class NbpFxProbeTests(unittest.TestCase):
    def test_historical_rate_keeps_exact_decimal_and_effective_date(self) -> None:
        getter = ScriptedGetter([response(200, rate_payload("USD", "2026-07-31", "3.7425"))])
        result = NbpFxProbe(getter, sleep=lambda _: None).historical("USD", date(2026, 7, 31))

        self.assertEqual(FxAvailability.AVAILABLE, result.availability)
        self.assertEqual(Decimal("3.7425"), result.pln_per_unit)
        self.assertEqual(date(2026, 7, 31), result.effective_date)
        self.assertEqual(1, result.attempts)
        self.assertIn("/A/USD/2026-07-31/2026-07-31/", getter.urls[0])

    def test_weekend_exact_date_is_unavailable_and_not_substituted(self) -> None:
        getter = ScriptedGetter([response(404, "404 NotFound - Brak danych")])
        result = NbpFxProbe(getter, sleep=lambda _: None).historical("EUR", date(2026, 8, 1))

        self.assertEqual(FxAvailability.UNAVAILABLE, result.availability)
        self.assertIsNone(result.pln_per_unit)
        self.assertEqual("not_found", result.reason)
        self.assertEqual(1, result.attempts)

    def test_current_rate_is_stale_when_latest_publication_predates_warsaw_as_of_date(self) -> None:
        getter = ScriptedGetter([response(200, rate_payload("USD", "2026-07-31"))])
        result = NbpFxProbe(getter, sleep=lambda _: None).current("USD", date(2026, 8, 1))

        self.assertEqual(FxAvailability.STALE, result.availability)
        self.assertEqual(date(2026, 7, 31), result.effective_date)
        self.assertEqual(Decimal("3.7425"), result.pln_per_unit)
        self.assertEqual("effective_date_before_as_of_date", result.reason)

    def test_retryable_429_then_success_retries_once(self) -> None:
        getter = ScriptedGetter(
            [
                response(429, "too many requests", {"Retry-After": "1"}),
                response(200, rate_payload("EUR", "2026-07-31", "4.3128")),
            ]
        )
        pauses: list[float] = []
        result = NbpFxProbe(getter, sleep=pauses.append, max_attempts=2).historical("EUR", date(2026, 7, 31))

        self.assertEqual(FxAvailability.AVAILABLE, result.availability)
        self.assertEqual(2, result.attempts)
        self.assertEqual([1.0], pauses)

    def test_invalid_json_is_unavailable_without_retry(self) -> None:
        getter = ScriptedGetter([response(200, "this is not json")])
        result = NbpFxProbe(getter, sleep=lambda _: None).historical("USD", date(2026, 7, 31))

        self.assertEqual(FxAvailability.UNAVAILABLE, result.availability)
        self.assertEqual("invalid_json", result.reason)
        self.assertEqual(1, result.attempts)

    def test_fetching_one_currency_failure_does_not_abort_other_currency(self) -> None:
        responses = {
            "USD": ScriptedGetter([response(503, "temporary"), response(503, "temporary")]),
            "EUR": ScriptedGetter([response(200, rate_payload("EUR", "2026-07-31", "4.3128"))]),
        }

        def getter(url: str, timeout_seconds: float) -> NbpHttpResponse:
            return responses["USD" if "/USD/" in url else "EUR"](url, timeout_seconds)

        results = probe_currencies(
            NbpFxProbe(getter, sleep=lambda _: None, max_attempts=2),
            ("USD", "EUR"),
            date(2026, 7, 31),
        )

        self.assertEqual(FxAvailability.UNAVAILABLE, results[0].availability)
        self.assertEqual(FxAvailability.AVAILABLE, results[1].availability)

    def test_wrong_currency_or_table_is_unavailable(self) -> None:
        payload = rate_payload("EUR", "2026-07-31")
        payload["table"] = "B"
        getter = ScriptedGetter([response(200, payload)])

        result = NbpFxProbe(getter, sleep=lambda _: None).historical("USD", date(2026, 7, 31))

        self.assertEqual(FxAvailability.UNAVAILABLE, result.availability)
        self.assertEqual("unexpected_table", result.reason)

    def test_report_contains_retrieval_and_provider_metadata(self) -> None:
        payload = {
            "generated_at": "2026-08-01T00:00:00+00:00",
            "source_timezone": "Europe/Warsaw",
            "provider": {"name": "Narodowy Bank Polski", "api_contract": "unversioned"},
            "scenarios": [],
        }
        report = NbpFxProbe.markdown_report(payload)
        self.assertIn("Europe/Warsaw", report)
        self.assertIn("unversioned", report)


if __name__ == "__main__":
    unittest.main()
