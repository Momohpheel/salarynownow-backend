<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->foreignId('deduction_type_id')->nullable()->constrained('deduction_types')->nullOnDelete();
            $table->string('deduction_name');
            $table->string('deduction_key')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->boolean('is_percentage')->default(false);
            $table->decimal('percentage_applied', 5, 2)->nullable();
            $table->decimal('base_amount', 12, 2)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_deductions');
    }
};
