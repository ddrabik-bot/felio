<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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

            $table->unique(
                ['canonical_instrument', 'provider', 'provider_symbol', 'session_date'],
                'market_data_snapshots_identity_unique',
            );
        });

        Schema::create('daily_ohlc_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_data_snapshot_id')->constrained()->cascadeOnDelete();
            $table->date('trading_date');
            $table->text('open');
            $table->text('high');
            $table->text('low');
            $table->text('close');
            $table->timestamps();

            $table->unique(['market_data_snapshot_id', 'trading_date'], 'daily_ohlc_observations_identity_unique');
        });

        Schema::create('corporate_actions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('market_data_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('action_type');
            $table->date('action_date');
            $table->text('value');
            $table->char('event_identity', 64);
            $table->timestamps();

            $table->unique(['market_data_snapshot_id', 'event_identity'], 'corporate_actions_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('corporate_actions');
        Schema::dropIfExists('daily_ohlc_observations');
        Schema::dropIfExists('market_data_snapshots');
    }
};
