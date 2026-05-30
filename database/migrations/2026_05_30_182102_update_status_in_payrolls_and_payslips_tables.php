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
        Schema::table('payrolls', function (Blueprint $table) {
            $table->string('status')->default('pending')->comment('pending, processing, completed, failed')->change();
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->string('status')->default('processing')->comment('processing, disbursed, failed')->after('net_salary');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->string('status')->default('completed')->change();
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
