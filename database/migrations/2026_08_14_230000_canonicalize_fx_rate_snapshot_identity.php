<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement(<<<'SQL'
                DELETE FROM fx_rate_snapshots
                WHERE id IN (
                    SELECT id
                    FROM (
                        SELECT id, ROW_NUMBER() OVER (
                            PARTITION BY provider_implementation_version, currency, requested_date
                            ORDER BY
                                CASE
                                    WHEN availability = 'available'
                                        AND effective_date = requested_date
                                        AND pln_per_unit IS NOT NULL THEN 0
                                    WHEN availability = 'available' THEN 1
                                    WHEN availability = 'stale' THEN 2
                                    ELSE 3
                                END,
                                retrieved_at DESC,
                                id DESC
                        ) AS row_number
                        FROM fx_rate_snapshots
                    ) AS duplicate_candidates
                    WHERE row_number > 1
                )
                SQL);

            Schema::table('fx_rate_snapshots', function (Blueprint $table): void {
                $table->dropUnique('fx_rate_snapshots_source_observation_unique');
                $table->unique(
                    ['provider_implementation_version', 'currency', 'requested_date'],
                    'fx_rate_snapshots_provider_currency_date_unique',
                );
            });
        });
    }

    public function down(): void
    {
        Schema::table('fx_rate_snapshots', function (Blueprint $table): void {
            $table->dropUnique('fx_rate_snapshots_provider_currency_date_unique');
            $table->unique(
                ['provider_implementation_version', 'currency', 'requested_date', 'source_observation_identity'],
                'fx_rate_snapshots_source_observation_unique',
            );
        });
    }
};
