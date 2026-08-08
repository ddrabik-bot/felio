# Synthetic XTB workbook fixtures

These XLSX files are deterministic, hand-built test inputs for the Stage 4 manual import contract. Every account, position, symbol, amount, timestamp, and comment is fabricated; no workbook was exported from a broker account.

| Fixture | Purpose |
| --- | --- |
| `synthetic-xtb-statement.xlsx` | Baseline workbook: two valid Cash Operations rows, one unsupported cash operation, and one representative Closed Positions row. |
| `synthetic-xtb-closed-positions-valid.xlsx` | Representative Closed Positions input with complete synthetic fields. The current contract rejects Closed Positions rows rather than treating them as open-position imports. |
| `synthetic-xtb-closed-positions-rejected.xlsx` | Closed Positions row with malformed timestamp/price and zero volume, covering rejected values. |
| `synthetic-xtb-closed-positions-unknown-instrument.xlsx` | Closed Positions row using the fabricated `SYNTH-UNKNOWN` symbol, covering an instrument that cannot be recognized from the workbook alone. |
| `synthetic-xtb-closed-positions-unresolved-reference.xlsx` | Closed Positions row using the fabricated `SYNTH-UNRESOLVED` symbol, covering a reference that requires an explicit mapping. |
| `synthetic-xtb-duplicate-rows.xlsx` | Repeated synthetic Cash Operations candidate rows plus a Closed Positions row, covering duplicate source identities. |
| `synthetic-xtb-empty-closed-positions.xlsx` | Valid workbook whose Closed Positions sheet contains headers but no data rows. |

Keep these fixtures small and synthetic. Do not replace them with real XTB exports or add private account, transaction, holding, or identity data.
