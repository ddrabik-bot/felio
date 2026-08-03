<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('broker');
            $table->string('account_reference');
            $table->timestamps();

            $table->unique(['broker', 'account_reference'], 'portfolio_accounts_broker_account_unique');
        });

        Schema::create('portfolio_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_account_id')->constrained()->cascadeOnDelete();
            $table->string('source_batch_identity');
            $table->timestampTz('imported_at');
            $table->timestamps();

            $table->unique(['portfolio_account_id', 'source_batch_identity'], 'portfolio_import_batches_source_unique');
        });

        Schema::create('portfolio_import_source_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_import_batch_id')->constrained()->cascadeOnDelete();
            $table->string('source_row_identity');
            $table->string('status');
            $table->string('canonical_instrument')->nullable();
            // PostgreSQL converts this exact source decimal to unconstrained NUMERIC below.
            $table->text('quantity')->nullable();
            $table->bigInteger('average_cost_pln_grosze')->nullable();
            $table->timestampTz('as_of');
            $table->jsonb('raw_values');
            $table->text('diagnostic')->nullable();
            $table->timestamps();

            $table->unique(['portfolio_import_batch_id', 'source_row_identity'], 'portfolio_import_source_rows_identity_unique');
        });

        Schema::create('portfolio_positions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_account_id')->constrained()->cascadeOnDelete();
            $table->string('canonical_instrument');
            // PostgreSQL converts this exact source decimal to unconstrained NUMERIC below.
            $table->text('quantity');
            $table->bigInteger('average_cost_pln_grosze')->nullable();
            $table->timestampTz('as_of');
            $table->foreignId('source_import_batch_id')->constrained('portfolio_import_batches')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['portfolio_account_id', 'canonical_instrument'], 'portfolio_positions_account_instrument_unique');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ([['portfolio_import_source_rows', 'quantity'], ['portfolio_positions', 'quantity']] as [$table, $column]) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN {$column} TYPE NUMERIC USING {$column}::NUMERIC");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_positions');
        Schema::dropIfExists('portfolio_import_source_rows');
        Schema::dropIfExists('portfolio_import_batches');
        Schema::dropIfExists('portfolio_accounts');
    }
};
