<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_enquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('offer_id')->nullable();
            $table->string('offer_name')->nullable();
            $table->morphs('submitter');
            $table->string('name', 255);
            $table->string('phone_number', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('company_name', 255)->nullable();
            $table->text('message');
            $table->string('status', 32)->default('open');
            $table->json('metadata')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
            // Intentional: NO extra indexes here — they are created safely by 000005
            // with short MySQL-compliant names to avoid 64-char identifier overflow.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_enquiries');
    }
};
