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
                ...$this->corporateActions($snapshotId, 'dividend', $snapshot->dividends, 'amount', $now),
                ...$this->corporateActions($snapshotId, 'split', $snapshot->splits, 'ratio', $now),
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

    /**
     * @param  list<array<string, string>>  $events
     * @return list<array<string, Carbon|int|string>>
     */
    private function corporateActions(int $snapshotId, string $type, array $events, string $valueField, Carbon $now): array
    {
        $fallbackOccurrences = [];

        return array_map(function (array $event) use ($snapshotId, $type, $valueField, $now, &$fallbackOccurrences): array {
            $date = $event['date'];
            $value = $event[$valueField];
            $providerEventId = trim($event['provider_event_id'] ?? '');
            // Provider IDs are authoritative: a provider retry must retain the
            // same identity even if the provider changes decimal formatting.
            $identityValue = $providerEventId === '' ? $this->canonicalExactDecimal($value) : '';
            $normalizedIdentity = implode("\x1f", [$type, $date, $identityValue]);

            $sourceIdentity = $providerEventId !== ''
                ? "provider\x1f{$providerEventId}"
                : "normalized\x1f{$normalizedIdentity}\x1f".($fallbackOccurrences[$normalizedIdentity] = ($fallbackOccurrences[$normalizedIdentity] ?? 0) + 1);

            return $this->corporateAction($snapshotId, $type, $date, $value, $identityValue, $sourceIdentity, $now);
        }, $events);
    }

    /** @return array<string, Carbon|int|string> */
    private function corporateAction(int $snapshotId, string $type, string $date, string $value, string $identityValue, string $sourceIdentity, Carbon $now): array
    {
        return [
            'market_data_snapshot_id' => $snapshotId,
            'action_type' => $type,
            'action_date' => $date,
            'value' => $value,
            'event_identity' => hash('sha256', implode("\x1f", [$type, $date, $identityValue, $sourceIdentity])),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function canonicalExactDecimal(string $value): string
    {
        $trimmed = trim($value);

        if (! preg_match('/^([+-]?)(?:(\d+)(?:\.(\d*))?|\.(\d+))$/', $trimmed, $matches)) {
            return $value;
        }

        $integer = ltrim(($matches[2] ?? '') !== '' ? $matches[2] : '0', '0');
        $fraction = rtrim(($matches[3] ?? '') !== '' ? $matches[3] : ($matches[4] ?? ''), '0');
        $integer = $integer === '' ? '0' : $integer;

        if ($integer === '0' && $fraction === '') {
            return '0';
        }

        return ($matches[1] === '-' ? '-' : '').$integer.($fraction === '' ? '' : ".{$fraction}");
    }
}
