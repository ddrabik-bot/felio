<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_data_refresh_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->string('run_date', 10);
            $table->string('subject_type');
            $table->string('subject');
            $table->string('availability');
            $table->text('reason')->nullable();
            $table->timestamps();
            $table->unique(['run_date', 'subject_type', 'subject'], 'market_data_refresh_outcomes_identity_unique');
        });

        Schema::create('portfolio_value_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_account_id')->constrained()->cascadeOnDelete();
            $table->date('valuation_date');
            $table->string('availability');
            $table->bigInteger('total_pln_grosze')->nullable();
            $table->string('source');
            $table->jsonb('diagnostics');
            $table->timestamps();
            $table->unique(['portfolio_account_id', 'valuation_date'], 'portfolio_value_snapshots_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_value_snapshots');
        Schema::dropIfExists('market_data_refresh_outcomes');
    }
};
