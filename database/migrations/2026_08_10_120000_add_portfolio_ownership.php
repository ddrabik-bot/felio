<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_accounts', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(false)->after('account_reference');
            $table->index(['user_id', 'is_active'], 'portfolio_accounts_user_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_accounts', function (Blueprint $table): void {
            $table->dropIndex('portfolio_accounts_user_active_index');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn('is_active');
        });
    }
};
