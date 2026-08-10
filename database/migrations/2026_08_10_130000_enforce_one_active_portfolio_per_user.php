<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX portfolio_accounts_one_active_per_user ON portfolio_accounts (user_id) WHERE is_active');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX portfolio_accounts_one_active_per_user');
    }
};
