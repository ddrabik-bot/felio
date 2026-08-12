#!/usr/bin/env python3
"""Small internal HTTP boundary around yfinance for Felio's Laravel service."""
from __future__ import annotations

import json
import math
import os
from datetime import UTC, datetime
from decimal import Decimal
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import unquote, urlparse

import yfinance as yf


def decimal_text(value: object) -> str:
    number = Decimal(str(value))
    if not number.is_finite():
        raise ValueError("non-finite provider decimal")
    return format(number, "f")


def optional_decimal(value: object) -> str | None:
    try:
        if value is None or math.isnan(float(value)):
            return None
        return decimal_text(value)
    except (TypeError, ValueError):
        return None


def event_rows(history: object, column: str, field: str) -> list[dict[str, str]]:
    if column not in history:
        return []
    values = history[column]
    return [
        {"date": index.date().isoformat(), field: decimal_text(value)}
        for index, value in values[values != 0].dropna().items()
    ]


def quote(symbol: str) -> dict[str, object]:
    history = yf.Ticker(symbol).history(
        period="5d", interval="1d", actions=True, auto_adjust=False, raise_errors=True, timeout=15,
    )
    if history is None or history.empty:
        raise LookupError("no daily history")
    metadata = yf.Ticker(symbol).get_history_metadata() or {}
    ohlc = []
    for index, row in history.iterrows():
        values = {key: optional_decimal(row[key]) for key in ("Open", "High", "Low", "Close")}
        if any(value is None for value in values.values()):
            continue
        ohlc.append({"date": index.date().isoformat(), "open": values["Open"], "high": values["High"], "low": values["Low"], "close": values["Close"]})
    if not ohlc:
        raise LookupError("no complete daily OHLC")
    return {
        "symbol": symbol,
        "exchange": str(metadata.get("exchangeName") or "UNKNOWN"),
        "quote_currency": str(metadata.get("currency") or "UNKNOWN"),
        "daily_ohlc": ohlc,
        "dividends": event_rows(history, "Dividends", "amount"),
        "splits": event_rows(history, "Stock Splits", "ratio"),
        "retrieved_at": datetime.now(UTC).isoformat(),
        "source_timezone": str(metadata.get("timezone") or "UTC"),
        "provider_version": f"yfinance-{yf.__version__}",
    }


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        parsed = urlparse(self.path)
        if parsed.path == "/health":
            return self.respond(200, {"status": "ok", "provider_version": f"yfinance-{yf.__version__}"})
        prefix = "/v1/quotes/"
        if not parsed.path.startswith(prefix) or not parsed.path[len(prefix):]:
            return self.respond(404, {"error": "not_found"})
        try:
            return self.respond(200, quote(unquote(parsed.path[len(prefix):])))
        except LookupError as error:
            return self.respond(404, {"error": "no_data", "reason": str(error)})
        except Exception as error:
            return self.respond(503, {"error": "unavailable", "reason": type(error).__name__})

    def respond(self, status: int, payload: dict[str, object]) -> None:
        body = json.dumps(payload, separators=(",", ":")).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format: str, *args: object) -> None:
        return


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", int(os.getenv("PORT", "8000"))), Handler).serve_forever()
