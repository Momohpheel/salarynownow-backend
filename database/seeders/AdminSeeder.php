<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create SuperAdmin
        User::updateOrCreate(
            ['email' => 'superadmin@salarynownow.com'],
            [
                'name' => 'System SuperAdmin',
                'password' => Hash::make('password'),
                'type' => User::TYPE_SUPERADMIN,
                'is_approved' => true,
                'is_active' => true,
            ]
        );

        // Create a default Merchant (Admin)
        User::updateOrCreate(
            ['email' => 'admin@salarynownow.com'],
            [
                'name' => 'Default Merchant',
                'link_name' => 'main',
                'password' => Hash::make('password'),
                'type' => User::TYPE_ADMIN,
                'status' => 'active',
                'is_approved' => true,
                'is_active' => true,
            ]
        );
    }
}
