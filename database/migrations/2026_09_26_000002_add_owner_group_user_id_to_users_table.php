<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'owner_group_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->bigInteger('owner_group_user_id')->unsigned()->nullable();
                $table->foreign('owner_group_user_id', 'users_owner_group_user_id_foreign')
                    ->references('id')->on('users')->onDelete('set null');
                $table->index('owner_group_user_id', 'users_og_uid_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'owner_group_user_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign('users_owner_group_user_id_foreign');
                $table->dropIndex('users_og_uid_idx');
                $table->dropColumn('owner_group_user_id');
            });
        }
    }
};
