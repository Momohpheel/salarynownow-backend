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
            // Personal Details (Step 1)
            $table->string('phone_number')->nullable()->after('email');
            
            // Company Information (Step 2)
            $table->string('company_name')->nullable()->after('phone_number');
            $table->string('rc_number')->nullable()->after('company_name');
            $table->string('industry')->nullable()->after('rc_number');
            $table->text('company_address')->nullable()->after('industry');
            $table->integer('number_of_staff')->nullable()->after('company_address');
            
            // KYB Documents (Step 3)
            $table->string('cac_certificate_path')->nullable()->after('number_of_staff');
            $table->string('director_id_path')->nullable()->after('cac_certificate_path');
            $table->string('utility_bill_path')->nullable()->after('director_id_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'phone_number',
                'company_name',
                'rc_number',
                'industry',
                'company_address',
                'number_of_staff',
                'cac_certificate_path',
                'director_id_path',
                'utility_bill_path'
            ]);
        });
    }
};
