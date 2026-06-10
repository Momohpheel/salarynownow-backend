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
            $table->string('contact_person')->nullable()->after('name');
            $table->string('state')->nullable()->after('company_address');
            $table->decimal('revenue_share', 5, 2)->default(0.00)->after('is_active');
            $table->string('plan_tier')->nullable()->after('revenue_share');
            $table->text('internal_notes')->nullable()->after('plan_tier');
            $table->string('status')->default('pending')->after('is_active'); // pending, active, suspended
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['contact_person', 'state', 'revenue_share', 'plan_tier', 'internal_notes', 'status']);
        });
    }
};
