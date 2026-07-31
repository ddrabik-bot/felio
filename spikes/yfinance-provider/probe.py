#!/usr/bin/env python3
"""Reproducible, isolated yfinance feasibility probe for Felio sample instruments."""

from __future__ import annotations

import argparse
import json
import platform
import sys
import time
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import yfinance as yf

SAMPLES = (
    ("OTLK.US", "OTLK", "US equity; Yahoo omits the .US suffix"),
    ("PZU.PL", "PZU.WA", "Warsaw Stock Exchange uses .WA"),
    ("PEO.PL", "PEO.WA", "Warsaw Stock Exchange uses .WA"),
    ("PKN.PL", "PKN.WA", "Legacy PKN ticker retained for compatibility"),
    ("XTB.PL", "XTB.WA", "Warsaw Stock Exchange uses .WA"),
    ("IUSQ.DE", "IUSQ.DE", "Xetra ETF suffix is retained"),
    ("EXIE.DE", "EXIE.DE", "Xetra ETF suffix is retained"),
    ("VBTC.DE", "VBTC.DE", "Xetra ETP suffix is retained"),
)


def as_json_value(value: Any) -> Any:
    """Convert pandas/numpy/yfinance scalar values to JSON-safe primitives."""
    if value is None or isinstance(value, (str, int, float, bool)):
        return value
    if hasattr(value, "item"):
        try:
            return as_json_value(value.item())
        except (TypeError, ValueError):
            pass
    if hasattr(value, "isoformat"):
        return value.isoformat()
    return str(value)


def error_text(stage: str, error: BaseException) -> dict[str, str]:
    return {"stage": stage, "type": type(error).__name__, "message": str(error)}


def unavailable_result(input_symbol: str, provider_symbol: str, mapping_note: str) -> dict[str, Any]:
    return {
        "input_symbol": input_symbol,
        "provider_symbol": provider_symbol,
        "mapping_note": mapping_note,
        "recognized": False,
        "exchange_currency": {},
        "history": {"available": False, "rows": 0},
        "dividends": {},
        "splits": {},
        "errors": [],
    }


def compact_actions(history: Any, column: str) -> dict[str, Any]:
    if column not in history:
        return {"count": 0, "events": []}

    values = history[column]
    nonzero = values[values != 0].dropna()
    return {
        "count": int(len(nonzero)),
        "events": [
            {"date": index.date().isoformat(), "value": as_json_value(value)}
            for index, value in nonzero.tail(10).items()
        ],
    }


def probe(input_symbol: str, provider_symbol: str, mapping_note: str) -> dict[str, Any]:
    result = unavailable_result(input_symbol, provider_symbol, mapping_note)
    ticker = yf.Ticker(provider_symbol)
    history = None

    for attempt in range(1, 3):
        try:
            history = ticker.history(
                period="max",
                interval="1d",
                actions=True,
                auto_adjust=False,
                raise_errors=True,
                timeout=15,
            )
            break
        except Exception as error:  # yfinance exposes several transport exception types.
            result["errors"].append(error_text(f"history attempt {attempt}", error))
            if attempt == 1:
                time.sleep(2)

    if history is None or history.empty:
        result["history"] = {"available": False, "rows": 0}
        return result

    result["recognized"] = True
    result["history"] = {
        "available": True,
        "rows": int(len(history)),
        "first_date": history.index.min().date().isoformat(),
        "last_date": history.index.max().date().isoformat(),
        "columns": list(history.columns),
    }
    result["dividends"] = compact_actions(history, "Dividends")
    result["splits"] = compact_actions(history, "Stock Splits")

    try:
        metadata = ticker.get_history_metadata()
        result["exchange_currency"]["history_metadata"] = {
            key: as_json_value(metadata.get(key))
            for key in ("exchangeName", "fullExchangeName", "currency", "instrumentType", "gmtoffset")
            if metadata.get(key) is not None
        }
    except Exception as error:
        result["errors"].append(error_text("history metadata", error))

    try:
        fast_info = ticker.fast_info
        result["exchange_currency"]["fast_info"] = {
            key: as_json_value(fast_info[key])
            for key in ("currency", "exchange", "quote_type")
            if key in fast_info
        }
    except Exception as error:
        result["errors"].append(error_text("fast info", error))

    return result


def safe_probe(input_symbol: str, provider_symbol: str, mapping_note: str) -> dict[str, Any]:
    """Keep an unexpected symbol failure from terminating the remaining batch."""
    try:
        return probe(input_symbol, provider_symbol, mapping_note)
    except Exception as error:  # Last-resort boundary around all yfinance/pandas handling.
        result = unavailable_result(input_symbol, provider_symbol, mapping_note)
        result["errors"].append(error_text("symbol probe", error))
        return result


def probe_samples(samples: tuple[tuple[str, str, str], ...] = SAMPLES) -> list[dict[str, Any]]:
    """Probe every requested symbol through the per-symbol failure boundary."""
    return [safe_probe(*sample) for sample in samples]


def markdown_report(payload: dict[str, Any]) -> str:
    lines = [
        "# yfinance provider feasibility results",
        "",
        f"Generated: `{payload['generated_at']}`",
        f"yfinance: `{payload['environment']['yfinance_version']}`",
        "",
        "This is a point-in-time, live Yahoo Finance probe. Re-run `make spike-yfinance` to refresh it.",
        "",
        "| Input | Provider symbol | Recognized | Exchange / currency | History | Splits | Dividends | Errors |",
        "|---|---|---:|---|---|---:|---:|---:|",
    ]
    for item in payload["symbols"]:
        metadata = item["exchange_currency"].get("history_metadata", {})
        exchange = metadata.get("exchangeName", "—")
        currency = metadata.get("currency", "—")
        history = item["history"]
        history_text = (
            f"{history['rows']} rows; {history['first_date']}–{history['last_date']}"
            if history.get("available")
            else "unavailable"
        )
        lines.append(
            "| {input_symbol} | {provider_symbol} | {recognized} | {exchange} / {currency} | {history} | {splits} | {dividends} | {errors} |".format(
                input_symbol=item["input_symbol"],
                provider_symbol=item["provider_symbol"],
                recognized="yes" if item["recognized"] else "no",
                exchange=exchange,
                currency=currency,
                history=history_text,
                splits=item["splits"].get("count", 0),
                dividends=item["dividends"].get("count", 0),
                errors=len(item["errors"]),
            )
        )
    return "\n".join(lines) + "\n"


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, default=Path("results/xtb-sample.json"))
    args = parser.parse_args()

    payload = {
        "generated_at": datetime.now(UTC).isoformat(),
        "environment": {
            "python": sys.version,
            "platform": platform.platform(),
            "yfinance_version": yf.__version__,
        },
        "symbols": probe_samples(),
        "limitations": [
            "yfinance is an unofficial Yahoo Finance client; it has no service-level agreement and Yahoo can change or throttle endpoints without notice.",
            "The probe is sequential, retries each history request once after a two-second delay, and continues after symbol-level failures.",
            "A non-empty history is the recognition criterion. Metadata and fast-info failures are recorded separately because quote availability can differ from chart-history availability.",
            "Provider symbols are provider-specific mappings, not a canonical instrument identifier. Validate exchange MIC, currency, and instrument identity before persisting data.",
        ],
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n")
    args.output.with_suffix(".md").write_text(markdown_report(payload))
    print(json.dumps({"output": str(args.output), "recognized": sum(x["recognized"] for x in payload["symbols"]), "total": len(SAMPLES)}))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
