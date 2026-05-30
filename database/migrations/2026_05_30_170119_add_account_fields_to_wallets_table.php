<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->string('account_number')->nullable()->after('currency');
            $table->string('account_name')->nullable()->after('account_number');
            $table->string('account_reference')->nullable()->after('account_name');
            $table->string('bank_name')->nullable()->after('account_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wallets', function (Blueprint $table) {
            $table->dropColumn([
                'account_number',
                'account_name',
                'account_reference',
                'bank_name'
            ]);
        });
    }
};
