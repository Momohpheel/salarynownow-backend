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
        Schema::table('users', function (Blueprint $table) {
            // Personal Details
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('job_title')->nullable()->after('last_name');
            $table->string('department')->nullable()->after('job_title');
            $table->date('start_date')->nullable()->after('department');

            // Bank Details for Staff
            $table->string('bank_name')->nullable()->after('start_date');
            $table->string('account_number')->nullable()->after('bank_name');
            $table->string('account_name')->nullable()->after('account_number');

            // Compensation
            $table->decimal('salary', 15, 2)->nullable()->after('account_name');

            // Pension Details
            $table->string('pfa_name')->nullable()->after('salary');
            $table->string('rsa_pin')->nullable()->after('pfa_name');
            $table->decimal('pension_employee_rate', 5, 2)->default(8.00)->after('rsa_pin');
            $table->decimal('pension_employer_rate', 5, 2)->default(10.00)->after('pension_employee_rate');

            // Status
            $table->string('invitation_status')->default('Not invited')->after('is_approved'); // Activated, Not invited
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'first_name',
                'last_name',
                'job_title',
                'department',
                'start_date',
                'bank_name',
                'account_number',
                'account_name',
                'salary',
                'pfa_name',
                'rsa_pin',
                'pension_employee_rate',
                'pension_employer_rate',
                'invitation_status'
            ]);
        });
    }
};
