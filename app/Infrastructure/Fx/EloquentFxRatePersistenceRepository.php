<?php

namespace App\Infrastructure\Fx;

use App\Domain\Fx\FxRatePersistenceRepository;
use App\Domain\Fx\FxRateResult;
use Illuminate\Support\Facades\DB;
use JsonException;

final class EloquentFxRatePersistenceRepository implements FxRatePersistenceRepository
{
    public function persist(FxRateResult $result): void
    {
        $metadata = $this->responseMetadata($result);
        $now = now();

        DB::statement(<<<'SQL'
            INSERT INTO fx_rate_snapshots (
                provider_implementation_version, currency, requested_date, source_observation_identity,
                effective_date, availability, pln_per_unit, reason, attempts, retrieved_at,
                api_endpoint, "table", source_timezone, provider_response_metadata, created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)
            ON CONFLICT (provider_implementation_version, currency, requested_date) DO UPDATE SET
                source_observation_identity = EXCLUDED.source_observation_identity,
                effective_date = EXCLUDED.effective_date,
                availability = EXCLUDED.availability,
                pln_per_unit = EXCLUDED.pln_per_unit,
                reason = EXCLUDED.reason,
                attempts = EXCLUDED.attempts,
                retrieved_at = EXCLUDED.retrieved_at,
                api_endpoint = EXCLUDED.api_endpoint,
                "table" = EXCLUDED."table",
                source_timezone = EXCLUDED.source_timezone,
                provider_response_metadata = EXCLUDED.provider_response_metadata,
                updated_at = EXCLUDED.updated_at
            WHERE fx_rate_snapshots.availability <> 'available' OR EXCLUDED.availability = 'available'
            SQL, [
                $result->providerImplementationVersion,
                $result->currency,
                $result->requestedDate->format('Y-m-d'),
                $this->sourceObservationIdentity($result, $metadata),
                $result->effectiveDate?->format('Y-m-d'),
                $result->availability->value,
                $result->plnPerUnit,
                $result->reason,
                $result->attempts,
                $result->retrievedAt,
                $result->apiEndpoint,
                $result->table,
                $result->sourceTimezone,
                $metadata,
                $now,
                $now,
            ]);
    }

    private function sourceObservationIdentity(FxRateResult $result, string $metadata): string
    {
        return hash('sha256', implode("\x1f", [
            $result->effectiveDate?->format('Y-m-d') ?? 'none',
            $result->availability->value,
            $result->apiEndpoint,
            $result->table,
            $result->sourceTimezone,
            $metadata,
        ]));
    }

    private function responseMetadata(FxRateResult $result): string
    {
        try {
            return json_encode([
                'provider_api_contract' => $result->providerApiContract,
                'table_number' => $result->tableNumber,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \LogicException('FX provider response metadata must be JSON serializable.', 0, $exception);
        }
    }
}
