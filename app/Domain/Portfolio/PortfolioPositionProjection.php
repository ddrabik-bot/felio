<?php

namespace App\Domain\Portfolio;

use InvalidArgumentException;

final class PortfolioPositionProjection
{
    /**
     * XTB cash trades are applied chronologically as an order-sensitive net
     * position. Other source rows retain legacy snapshot overwrite semantics.
     *
     * @param  iterable<array{canonicalInstrument: string, quantity: string, averageCostPlnGrosze: ?int, asOf: string, sourceImportBatchId: int, id: int, rawValues: array<string, mixed>}>  $rows
     * @return array<string, array{canonicalInstrument: string, quantity: string, averageCostPlnGrosze: ?int, asOf: string, sourceImportBatchId: int, id: int}>
     */
    public static function rebuild(iterable $rows): array
    {
        $positions = [];

        foreach ($rows as $row) {
            $key = $row['canonicalInstrument'];
            $operation = $row['rawValues']['xtb_operation'] ?? null;
            if (! in_array($operation, ['buy', 'sell'], true)) {
                $snapshotCostBasis = $row['averageCostPlnGrosze'] === null
                    ? null
                    : bcmul($row['quantity'], (string) $row['averageCostPlnGrosze'], self::scale($row['quantity']));
                $positions[$key] = self::position($row, $row['quantity'], $row['averageCostPlnGrosze'], $snapshotCostBasis);

                continue;
            }

            $current = $positions[$key] ?? null;
            if ($operation === 'buy') {
                $quantity = bcadd($current['quantity'] ?? '0', $row['quantity'], self::scale($current['quantity'] ?? '0', $row['quantity']));
                $costBasis = $current['costBasis'] ?? '0';
                if ($row['averageCostPlnGrosze'] === null || ($current !== null && $current['averageCostPlnGrosze'] === null)) {
                    $positions[$key] = self::position($row, $quantity, null);

                    continue;
                }
                $costBasis = bcadd($costBasis, bcmul($row['quantity'], (string) $row['averageCostPlnGrosze'], self::scale($row['quantity'])), self::scale($costBasis, $row['quantity']));
                $positions[$key] = self::position($row, $quantity, self::costFromBasis($costBasis, $quantity), $costBasis);

                continue;
            }

            if ($current === null || bccomp($current['quantity'], $row['quantity'], self::scale($current['quantity'], $row['quantity'])) < 0) {
                unset($positions[$key]);

                continue;
            }

            $quantity = bcsub($current['quantity'], $row['quantity'], self::scale($current['quantity'], $row['quantity']));
            if (bccomp($quantity, '0', self::scale($quantity)) === 0) {
                unset($positions[$key]);

                continue;
            }

            $costBasis = $current['costBasis'] ?? null;
            if ($costBasis !== null) {
                $averageCost = bcdiv($costBasis, $current['quantity'], self::divisionScale($costBasis, $current['quantity']));
                $realizedCost = bcmul($row['quantity'], $averageCost, self::scale($row['quantity'], $averageCost));
                $costBasis = bcsub($costBasis, $realizedCost, self::scale($costBasis, $realizedCost));
            }
            $positions[$key] = self::position($row, $quantity, $current['averageCostPlnGrosze'], $costBasis);
        }

        return $positions;
    }

    private static function costFromBasis(string $costBasis, string $quantity): int
    {
        $cost = bcdiv($costBasis, $quantity, 0);
        if (bccomp($cost, (string) PHP_INT_MAX, 0) === 1) {
            throw new InvalidArgumentException('Average position cost exceeds the supported integer range.');
        }

        return (int) $cost;
    }

    /** @param array{canonicalInstrument: string, asOf: string, sourceImportBatchId: int, id: int} $row @return array{canonicalInstrument: string, quantity: string, averageCostPlnGrosze: ?int, asOf: string, sourceImportBatchId: int, id: int, costBasis?: string} */
    private static function position(array $row, string $quantity, ?int $cost, ?string $costBasis = null): array
    {
        $position = [
            'canonicalInstrument' => $row['canonicalInstrument'],
            'quantity' => $quantity,
            'averageCostPlnGrosze' => $cost,
            'asOf' => $row['asOf'],
            'sourceImportBatchId' => $row['sourceImportBatchId'],
            'id' => $row['id'],
        ];
        if ($costBasis !== null) {
            $position['costBasis'] = $costBasis;
        }

        return $position;
    }

    private static function divisionScale(string ...$values): int
    {
        return max(20, self::scale(...$values) + 20);
    }

    private static function scale(string ...$values): int
    {
        return max(array_map(static fn (string $value): int => ($dot = strpos($value, '.')) === false ? 0 : strlen($value) - $dot - 1, $values));
    }
}
