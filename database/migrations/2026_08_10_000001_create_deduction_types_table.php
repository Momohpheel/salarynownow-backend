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
            [
                'name' => 'PAYE / Income Tax',
                'description' => 'Personal income tax withheld at source',
                'default_amount' => 0,
                'is_percentage' => false,
                'percentage_value' => null,
                'is_active' => true,
                'is_system' => true,
                'system_key' => 'tax',
            ],
            [
                'name' => 'Pension (Employee)',
                'description' => 'Employee pension contribution 8%',
                'default_amount' => 0,
                'is_percentage' => true,
                'percentage_value' => 8.00,
                'is_active' => true,
                'is_system' => true,
                'system_key' => 'pension_ee',
            ],
            [
                'name' => 'NHF',
                'description' => 'National Housing Fund 2.5%',
                'default_amount' => 0,
                'is_percentage' => true,
                'percentage_value' => 2.50,
                'is_active' => true,
                'is_system' => true,
                'system_key' => 'nhf',
            ],
            [
                'name' => 'HMO / Health Insurance',
                'description' => 'Employer sponsored HMO pass-through',
                'default_amount' => 0,
                'is_percentage' => false,
                'percentage_value' => null,
                'is_active' => true,
                'is_system' => true,
                'system_key' => 'hmo',
            ],
            [
                'name' => 'Company Loan',
                'description' => 'Monthly loan repayment deduction',
                'default_amount' => 0,
                'is_percentage' => false,
                'percentage_value' => null,
                'is_active' => true,
                'is_system' => true,
                'system_key' => 'loan',
            ],
            [
                'name' => 'Salary Advance',
                'description' => 'Advance recovered from current salary',
                'default_amount' => 0,
                'is_percentage' => false,
                'percentage_value' => null,
                'is_active' => true,
                'is_system' => true,
                'system_key' => 'advance',
            ],
            [
                'name' => 'Other',
                'description' => 'Miscellaneous deduction',
                'default_amount' => 0,
                'is_percentage' => false,
                'percentage_value' => null,
                'is_active' => true,
                'is_system' => true,
                'system_key' => 'other',
            ],
        ];

        $inserts = array_map(function ($row) use ($now) {
            return [
                'user_id' => 1,
                'name' => $row['name'],
                'description' => $row['description'],
                'default_amount' => $row['default_amount'],
                'is_percentage' => $row['is_percentage'] ? 1 : 0,
                'percentage_value' => $row['percentage_value'],
                'is_active' => $row['is_active'] ? 1 : 0,
                'is_system' => $row['is_system'] ? 1 : 0,
                'system_key' => $row['system_key'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $seedDefaults);

        try {
            Schema::disableForeignKeyConstraints();
            $firstUser = DB::table('users')->orderBy('id')->value('id');
            if ($firstUser) {
                foreach ($inserts as &$r) {
                    $r['user_id'] = $firstUser;
                }
                unset($r);
            }
            DB::table('deduction_types')->insert($inserts);
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_types');
    }
};
