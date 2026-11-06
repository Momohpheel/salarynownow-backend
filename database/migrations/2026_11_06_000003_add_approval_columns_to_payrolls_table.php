<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks who requested a payroll run and who approved/rejected it, so runs
     * submitted by users without the "create_payroll" permission can be held
     * as "pending approval" until an approver signs off.
     */
    public function up(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            if (!Schema::hasColumn('payrolls', 'requested_by')) {
                $table->unsignedBigInteger('requested_by')->nullable()->after('user_id');
            }
            if (!Schema::hasColumn('payrolls', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('requested_by');
            }
            if (!Schema::hasColumn('payrolls', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('processed_at');
            }
            if (!Schema::hasColumn('payrolls', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('payrolls', 'rejection_reason')) {
                $table->string('rejection_reason', 500)->nullable()->after('rejected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payrolls', function (Blueprint $table) {
            foreach (['requested_by', 'approved_by', 'approved_at', 'rejected_at', 'rejection_reason'] as $column) {
                if (Schema::hasColumn('payrolls', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
