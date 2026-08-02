<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Charge;
use App\Models\User;

class ChargeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $admin = User::where('type', User::TYPE_ADMIN)->first();

        Charge::updateOrCreate(
            ['name' => 'wallet-top-up'],
            [
                'type' => 'percentage',
                'amount' => 1.5,
                'admin_id' => $admin->id,
            ]
        );

        Charge::updateOrCreate(
            ['name' => 'disbursement'],
            [
                'type' => 'fixed',
                'amount' => 150,
                'admin_id' => $admin->id,
            ]
        );
    }
}
