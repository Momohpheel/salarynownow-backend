<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            if (!Schema::hasColumn('payslips', 'failure_reason')) {
                $table->text('failure_reason')->nullable()->after('status');
            }
            if (!Schema::hasColumn('payslips', 'failure_code')) {
                $table->string('failure_code', 64)->nullable()->after('failure_reason');
            }
        });

        Schema::table('payrolls', function (Blueprint $table) {
            if (!Schema::hasColumn('payrolls', 'failure_summary')) {
                $table->json('failure_summary')->nullable()->after('period_end');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            if (Schema::hasColumn('payslips', 'failure_code')) {
                $table->dropColumn('failure_code');
            }
            if (Schema::hasColumn('payslips', 'failure_reason')) {
                $table->dropColumn('failure_reason');
            }
        });

        Schema::table('payrolls', function (Blueprint $table) {
            if (Schema::hasColumn('payrolls', 'failure_summary')) {
                $table->dropColumn('failure_summary');
            }
        });
    }
};
