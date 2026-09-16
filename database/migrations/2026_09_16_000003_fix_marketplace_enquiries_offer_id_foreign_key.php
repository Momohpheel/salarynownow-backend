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
        Schema::table('marketplace_enquiries', function (Blueprint $table) {
            $table->dropForeign(['offer_id']);
            $table->unsignedBigInteger('offer_id')->nullable()->change();
            $table->index('offer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('marketplace_enquiries', function (Blueprint $table) {
            $table->dropIndex(['offer_id']);
        });
    }
};
