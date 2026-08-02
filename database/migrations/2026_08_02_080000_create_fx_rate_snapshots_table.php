<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fx_rate_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_implementation_version');
            $table->string('currency', 3);
            $table->date('requested_date');
            $table->date('effective_date')->nullable();
            $table->string('availability');
            // PostgreSQL converts this exact source decimal to NUMERIC below.
            $table->text('pln_per_unit')->nullable();
            $table->string('reason')->nullable();
            $table->unsignedInteger('attempts');
            $table->timestampTz('retrieved_at');
            $table->text('api_endpoint');
            $table->string('table');
            $table->string('source_timezone');
            $table->jsonb('provider_response_metadata');
            $table->char('source_observation_identity', 64);
            $table->timestamps();

            $table->unique(
                ['provider_implementation_version', 'currency', 'requested_date', 'source_observation_identity'],
                'fx_rate_snapshots_source_observation_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE fx_rate_snapshots ALTER COLUMN pln_per_unit TYPE NUMERIC USING pln_per_unit::NUMERIC');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fx_rate_snapshots');
    }
};
