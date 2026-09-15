<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->integer('login_attempts')->default(0)->after('otp_attempts');
            $table->timestamp('lockout_until')->nullable()->after('login_attempts');
            $table->text('alternative_emails')->nullable()->after('bvn');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['login_attempts', 'lockout_until', 'alternative_emails']);
        });
    }
};
