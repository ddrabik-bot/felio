"""Regression tests for per-symbol error isolation in the yfinance spike."""

import unittest
from unittest.mock import patch

import probe


class ProbeIsolationTests(unittest.TestCase):
    def test_safe_probe_records_unexpected_ticker_failure(self) -> None:
        with patch.object(probe.yf, "Ticker", side_effect=RuntimeError("unexpected ticker failure")):
            result = probe.safe_probe("BAD.US", "BAD", "test mapping")

        self.assertEqual(result["input_symbol"], "BAD.US")
        self.assertEqual(result["provider_symbol"], "BAD")
        self.assertFalse(result["recognized"])
        self.assertEqual(result["history"], {"available": False, "rows": 0})
        self.assertEqual(result["errors"], [{
            "stage": "symbol probe",
            "type": "RuntimeError",
            "message": "unexpected ticker failure",
        }])

    def test_probe_samples_continues_after_a_symbol_failure(self) -> None:
        samples = (
            ("FIRST.US", "FIRST", "first mapping"),
            ("FAIL.US", "FAIL", "failing mapping"),
            ("LAST.US", "LAST", "last mapping"),
        )
        successful_result = {"recognized": True, "input_symbol": "placeholder"}
        failed_result = {"recognized": False, "input_symbol": "FAIL.US"}

        with patch.object(probe, "safe_probe", side_effect=[successful_result, failed_result, successful_result]) as safe_probe:
            results = probe.probe_samples(samples)

        self.assertEqual(results, [successful_result, failed_result, successful_result])
        self.assertEqual(
            safe_probe.call_args_list,
            [
                (("FIRST.US", "FIRST", "first mapping"),),
                (("FAIL.US", "FAIL", "failing mapping"),),
                (("LAST.US", "LAST", "last mapping"),),
            ],
        )


if __name__ == "__main__":
    unittest.main()
