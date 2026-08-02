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
        $identity = [
            'provider_implementation_version' => $result->providerImplementationVersion,
            'currency' => $result->currency,
            'requested_date' => $result->requestedDate->format('Y-m-d'),
            'source_observation_identity' => $this->sourceObservationIdentity($result, $metadata),
        ];

        DB::table('fx_rate_snapshots')->upsert([
            [
                ...$identity,
                'effective_date' => $result->effectiveDate?->format('Y-m-d'),
                'availability' => $result->availability->value,
                'pln_per_unit' => $result->plnPerUnit,
                'reason' => $result->reason,
                'attempts' => $result->attempts,
                'retrieved_at' => $result->retrievedAt,
                'api_endpoint' => $result->apiEndpoint,
                'table' => $result->table,
                'source_timezone' => $result->sourceTimezone,
                'provider_response_metadata' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], array_keys($identity), [
            'effective_date',
            'availability',
            'pln_per_unit',
            'reason',
            'attempts',
            'retrieved_at',
            'api_endpoint',
            'table',
            'source_timezone',
            'provider_response_metadata',
            'updated_at',
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
