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
        User::updateOrCreate(
            ['email' => 'admin@salarynownow.com'],
            [
                'name' => 'System Admin',
                'password' => Hash::make('password'),
                'type' => User::TYPE_ADMIN,
                'is_approved' => true,
                'is_active' => true,
            ]
        );
    }
}
