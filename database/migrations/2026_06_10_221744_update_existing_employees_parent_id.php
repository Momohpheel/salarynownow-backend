<?php

use App\Models\User;
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
        $defaultMerchant = User::where('type', User::TYPE_ADMIN)
            ->where('link_name', 'main')
            ->first();

        if ($defaultMerchant) {
            User::where('type', User::TYPE_EMPLOYEE)
                ->whereNull('parent_id')
                ->update(['parent_id' => $defaultMerchant->id]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
