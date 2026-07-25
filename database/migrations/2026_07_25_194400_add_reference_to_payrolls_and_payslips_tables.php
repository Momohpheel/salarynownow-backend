<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            $table->string('reference')->nullable()->unique()->after('id');
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->string('reference')->nullable()->unique()->after('id');
        });

        DB::table('payrolls')
            ->whereNull('reference')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($payroll) {
                DB::table('payrolls')
                    ->where('id', $payroll->id)
                    ->update([
                        'reference' => sprintf('PRL-%08d', $payroll->id),
                    ]);
            });

        DB::table('payslips')
            ->whereNull('reference')
            ->orderBy('id')
            ->get(['id'])
            ->each(function ($payslip) {
                DB::table('payslips')
                    ->where('id', $payslip->id)
                    ->update([
                        'reference' => sprintf('PSL-%08d', $payslip->id),
                    ]);
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });

        Schema::table('payrolls', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
