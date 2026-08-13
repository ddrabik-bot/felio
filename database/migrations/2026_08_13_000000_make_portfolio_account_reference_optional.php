<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Broker account reference is now optional metadata: an active XTB portfolio
        // can exist without a typed account number, and imports flow into the selected
        // portfolio regardless of the account source inside the file.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE portfolio_accounts ALTER COLUMN account_reference DROP NOT NULL');
        } else {
            Schema::table('portfolio_accounts', function (Blueprint $table): void {
                $table->string('account_reference')->nullable()->change();
            });
        }

        // On PostgreSQL a UNIQUE index treats NULLs as distinct, so multiple portfolios
        // with a NULL reference are allowed; keep uniqueness for non-null references.
        Schema::table('portfolio_accounts', function (Blueprint $table): void {
            $table->dropUnique('portfolio_accounts_broker_account_unique');
        });
        Schema::table('portfolio_accounts', function (Blueprint $table): void {
            $table->unique(['broker', 'account_reference'], 'portfolio_accounts_broker_account_unique');
        });
    }

    public function down(): void
    {
        $concat = DB::getDriverName() === 'mysql'
            ? DB::raw("CONCAT('XTB-UNSPECIFIED-', id)")
            : DB::raw("'XTB-UNSPECIFIED-' || id");

        DB::table('portfolio_accounts')->whereNull('account_reference')->update([
            'account_reference' => $concat,
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE portfolio_accounts ALTER COLUMN account_reference SET NOT NULL');
        } else {
            Schema::table('portfolio_accounts', function (Blueprint $table): void {
                $table->string('account_reference')->nullable(false)->change();
            });
        }
    }
};