<?php

namespace App\Infrastructure\MarketData;

use App\Domain\MarketData\InstrumentMarketData;
use App\Domain\MarketData\MarketDataPersistenceRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class EloquentMarketDataPersistenceRepository implements MarketDataPersistenceRepository
{
    public function persist(InstrumentMarketData $marketData, string $sessionDate): void
    {
        $snapshot = $marketData->snapshot;

        if ($snapshot === null) {
            return;
        }

        $now = now();
        $identity = [
            'canonical_instrument' => $marketData->mapping->instrument->value,
            'provider' => $marketData->mapping->provider->value,
            'provider_symbol' => $marketData->mapping->symbol->value,
            'session_date' => $sessionDate,
        ];

        DB::transaction(function () use ($identity, $snapshot, $now): void {
            DB::table('market_data_snapshots')->upsert([
                [
                    ...$identity,
                    'exchange' => $snapshot->exchange,
                    'quote_currency' => $snapshot->quoteCurrency,
                    'retrieved_at' => $snapshot->retrievedAt,
                    'source_timezone' => $snapshot->sourceTimezone,
                    'provider_version' => $snapshot->providerVersion,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ], array_keys($identity), [
                'exchange',
                'quote_currency',
                'retrieved_at',
                'source_timezone',
                'provider_version',
                'updated_at',
            ]);

            $snapshotId = DB::table('market_data_snapshots')->where($identity)->value('id');

            $dailyOhlc = array_map(static fn ($observation): array => [
                'market_data_snapshot_id' => $snapshotId,
                'trading_date' => $observation->date,
                'open' => $observation->open,
                'high' => $observation->high,
                'low' => $observation->low,
                'close' => $observation->close,
                'created_at' => $now,
                'updated_at' => $now,
            ], $snapshot->dailyOhlc);

            if ($dailyOhlc !== []) {
                DB::table('daily_ohlc_observations')->upsert(
                    $dailyOhlc,
                    ['market_data_snapshot_id', 'trading_date'],
                    ['open', 'high', 'low', 'close', 'updated_at'],
                );
            }

            $corporateActions = [
                ...array_map(fn (array $event, int $index): array => $this->corporateAction($snapshotId, 'dividend', $event['date'], $event['amount'], $event['provider_event_id'] ?? (string) $index, $now), $snapshot->dividends, array_keys($snapshot->dividends)),
                ...array_map(fn (array $event, int $index): array => $this->corporateAction($snapshotId, 'split', $event['date'], $event['ratio'], $event['provider_event_id'] ?? (string) $index, $now), $snapshot->splits, array_keys($snapshot->splits)),
            ];

            if ($corporateActions !== []) {
                DB::table('corporate_actions')->upsert(
                    $corporateActions,
                    ['market_data_snapshot_id', 'event_identity'],
                    ['updated_at'],
                );
            }
        });
    }

    /** @return array<string, Carbon|int|string> */
    private function corporateAction(int $snapshotId, string $type, string $date, string $value, string $providerEventIdentity, Carbon $now): array
    {
        return [
            'market_data_snapshot_id' => $snapshotId,
            'action_type' => $type,
            'action_date' => $date,
            'value' => $value,
            'event_identity' => hash('sha256', implode("\x1f", [$type, $date, $value, $providerEventIdentity])),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
