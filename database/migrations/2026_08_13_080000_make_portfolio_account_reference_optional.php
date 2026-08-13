<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_accounts', function (Blueprint $table): void {
            $table->string('account_reference')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Null account references are valid portfolio metadata and cannot be
        // losslessly converted back to the former NOT NULL schema.
    }
};
