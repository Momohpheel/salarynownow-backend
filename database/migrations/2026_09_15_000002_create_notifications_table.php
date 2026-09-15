<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('category', 32)->default('system')->index();
            $table->string('type', 64)->nullable();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('icon', 64)->nullable();
            $table->string('deep_link')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
