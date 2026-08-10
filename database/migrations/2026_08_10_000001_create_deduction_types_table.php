<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deduction_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('default_amount', 12, 2)->default(0);
            $table->boolean('is_percentage')->default(false);
            $table->decimal('percentage_value', 5, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('system_key')->nullable()->unique();
            $table->timestamps();
        });

        $now = now();
        $seedDefaults = [
            ['name' => 'PAYE / Income Tax', 'system_key' => 'tax', 'description' => 'Personal income tax withheld at source', 'is_system' => true],
            ['name' => 'Pension (Employee)', 'system_key' => 'pension_ee', 'description' => 'Employee pension contribution 8%', 'is_system' => true, 'is_percentage' => true, 'percentage_value' => 8.00],
            ['name' => 'NHF', 'system_key' => 'nhf', 'description' => 'National Housing Fund 2.5%', 'is_system' => true, 'is_percentage' => true, 'percentage_value' => 2.50],
            ['name' => 'HMO / Health Insurance', 'system_key' => 'hmo', 'description' => 'Employer sponsored HMO pass-through', 'is_system' => false],
            ['name' => 'Company Loan', 'system_key' => 'loan', 'description' => 'Monthly loan repayment deduction', 'is_system' => false],
            ['name' => 'Salary Advance', 'system_key' => 'advance', 'description' => 'Advance recovered from current salary', 'is_system' => false],
            ['name' => 'Other', 'system_key' => 'other', 'description' => 'Miscellaneous deduction', 'is_system' => false],
        ];

        $inserts = array_map(function ($row) use ($now) {
            return array_merge($row, [
                'user_id' => 0,
                'default_amount' => 0,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }, $seedDefaults);

        DB::table('deduction_types')->insert($inserts);
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_types');
    }
};
