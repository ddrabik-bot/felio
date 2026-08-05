<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_data_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('canonical_instrument');
            $table->string('provider');
            $table->string('provider_symbol');
            $table->string('exchange');
            $table->string('quote_currency', 3);
            $table->date('session_date');
            $table->timestampTz('retrieved_at');
            $table->string('source_timezone');
            $table->string('provider_version');
            $table->timestamps();

            // Snapshot upsert conflict key: canonical instrument + provider + provider symbol + session date.
            $table->unique(
                ['canonical_instrument', 'provider', 'provider_symbol', 'session_date'],
                'market_data_snapshots_identity_unique',
            );
        });

        Schema::create('daily_ohlc_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_data_snapshot_id')->constrained()->cascadeOnDelete();
            $table->date('trading_date');
            // PostgreSQL columns are converted to unconstrained NUMERIC below.
            // SQLite keeps TEXT in tests so its driver does not coerce exact input.
            $table->text('open');
            $table->text('high');
            $table->text('low');
            $table->text('close');
            $table->timestamps();

            // Daily OHLC upsert conflict key: snapshot + trading date.
            $table->unique(['market_data_snapshot_id', 'trading_date'], 'daily_ohlc_observations_identity_unique');
        });

        Schema::create('corporate_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_data_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('action_type');
            $table->date('action_date');
            // PostgreSQL converts this to unconstrained NUMERIC below.
            $table->text('value');
            $table->char('event_identity', 64);
            $table->timestamps();

            // Corporate-action upsert conflict key: snapshot + deterministic event identity.
            // event_identity is derived from provider event identity when present; otherwise the
            // implementation must include normalized type/date/value and an occurrence dimension
            // to distinguish repeated otherwise-equal normalized events.
            $table->unique(['market_data_snapshot_id', 'event_identity'], 'corporate_actions_identity_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                ['daily_ohlc_observations', 'open'],
                ['daily_ohlc_observations', 'high'],
                ['daily_ohlc_observations', 'low'],
                ['daily_ohlc_observations', 'close'],
                ['corporate_actions', 'value'],
            ] as [$table, $column]) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE NUMERIC USING {$column}::NUMERIC");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_actions');
        Schema::dropIfExists('daily_ohlc_observations');
        Schema::dropIfExists('market_data_snapshots');
    }
};
