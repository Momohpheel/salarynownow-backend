<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->foreignId('employer_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('employer_id');
        });

        $roles = DB::table('roles')->select('id')->get();

        foreach ($roles as $role) {
            $assignedUser = DB::table('users')
                ->select('id', 'type', 'employer_id', 'parent_id')
                ->where('role_id', $role->id)
                ->orderBy('id')
                ->first();

            if (! $assignedUser) {
                continue;
            }

            $employerId = match ($assignedUser->type) {
                'employee' => $assignedUser->employer_id ?: $assignedUser->id,
                'staff' => $assignedUser->parent_id,
                default => $assignedUser->employer_id ?: $assignedUser->parent_id ?: $assignedUser->id,
            };

            DB::table('roles')
                ->where('id', $role->id)
                ->update(['employer_id' => $employerId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employer_id');
        });
    }
};
