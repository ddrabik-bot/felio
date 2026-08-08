<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_import_batches', function (Blueprint $table): void {
            $table->string('status')->default('COMPLETED')->after('imported_at');
        });

        Schema::create('portfolio_import_recalculation_boundaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('deleted_import_batch_id');
            $table->foreignId('portfolio_account_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('boundary_as_of');
            $table->timestampTz('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_import_recalculation_boundaries');
        Schema::table('portfolio_import_batches', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }
};
