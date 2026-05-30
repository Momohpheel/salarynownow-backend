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
        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Employee who ran it
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->integer('staff_count');
            $table->string('status')->default('completed'); // completed, pending
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('salary_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // Employee (Company) owner
            $table->foreignId('staff_id')->constrained('users')->onDelete('cascade'); // Staff who requested
            $table->decimal('amount', 15, 2);
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salary_advances');
        Schema::dropIfExists('payrolls');
    }
};
