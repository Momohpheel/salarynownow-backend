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
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('pension');
            $table->decimal('pension_employee', 15, 2)->nullable()->after('gross_salary');
            $table->decimal('pension_employer', 15, 2)->nullable()->after('pension_employee');
            $table->decimal('tax_deduction', 15, 2)->nullable()->after('pension_employer');
            $table->decimal('nhf', 15, 2)->nullable()->after('tax_deduction');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('pension', 15, 2)->after('gross_salary');
            $table->dropColumn(['pension_employee', 'pension_employer', 'tax_deduction', 'nhf']);
        });
    }
};
