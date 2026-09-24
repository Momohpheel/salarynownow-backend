<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_configs', function (Blueprint $table) {
            $table->id();
            $table->enum('scope_type', ['platform', 'merchant', 'partner', 'employer']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->unsignedBigInteger('set_by_user_id');
            $table->enum('event', ['inflow_topup', 'outflow_disbursement']);
            $table->enum('calculation_type', ['flat', 'percentage']);
            $table->decimal('value', 15, 2);
            $table->decimal('cap_amount', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('scope_id', 'fc_scope_id_fk')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->foreign('set_by_user_id', 'fc_set_by_fk')
                ->references('id')->on('users')
                ->onDelete('cascade');

            $table->unique(
                ['scope_type', 'scope_id', 'event'],
                'fc_scope_event_unq'
            );

            $table->index(['scope_type', 'event'], 'fc_scope_event_idx');
            $table->index(['scope_type', 'scope_id'], 'fc_scope_type_id_idx');
            $table->index(['event', 'is_active'], 'fc_event_active_idx');
            $table->index('set_by_user_id', 'fc_set_by_idx');
            $table->index('scope_id', 'fc_scope_id_idx');
        });

        $now = now();

        $firstSuperAdminId = DB::table('users')
            ->where('type', 'superadmin')
            ->orderBy('id')
            ->value('id');

        if (!$firstSuperAdminId) {
            $firstSuperAdminId = DB::table('users')
                ->orderBy('id')
                ->value('id') ?? 1;
        }

        $seedDefaults = [
            [
                'scope_type' => 'platform',
                'scope_id' => null,
                'event' => 'inflow_topup',
                'calculation_type' => 'percentage',
                'value' => 0.00,
                'cap_amount' => null,
            ],
            [
                'scope_type' => 'platform',
                'scope_id' => null,
                'event' => 'outflow_disbursement',
                'calculation_type' => 'percentage',
                'value' => 0.00,
                'cap_amount' => null,
            ],
        ];

        $inserts = array_map(function ($row) use ($now, $firstSuperAdminId) {
            return [
                'scope_type' => $row['scope_type'],
                'scope_id' => $row['scope_id'],
                'set_by_user_id' => $firstSuperAdminId,
                'event' => $row['event'],
                'calculation_type' => $row['calculation_type'],
                'value' => $row['value'],
                'cap_amount' => $row['cap_amount'],
                'is_active' => 1,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }, $seedDefaults);

        DB::table('fee_configs')->insert($inserts);
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_configs');
    }
};
