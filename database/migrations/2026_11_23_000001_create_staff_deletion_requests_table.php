<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks requests to delete a staff member. When a team member without
     * approval rights removes a staff member, the action is held here as
     * "pending" until an approver (owner/admin or a holder of the
     * "approve_staff_deletion" permission) signs off.
     */
    public function up(): void
    {
        if (Schema::hasTable('staff_deletion_requests')) {
            return;
        }

        Schema::create('staff_deletion_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('employer_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('reason', 500)->nullable();
            $table->string('status', 30)->default('pending'); // pending|approved|rejected
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['employer_id', 'status']);
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_deletion_requests');
    }
};
