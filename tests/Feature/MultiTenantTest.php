<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_create_merchant()
    {
        $superadmin = User::factory()->create(['type' => User::TYPE_SUPERADMIN]);

        $response = $this->actingAs($superadmin)
            ->postJson('/api/superadmin/merchants', [
                'name' => 'Merchant One',
                'email' => 'merchant1@example.com',
                'password' => 'password',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.type', User::TYPE_ADMIN)
            ->assertJsonPath('data.link_name', 'merchant-one');

        $this->assertDatabaseHas('users', [
            'email' => 'merchant1@example.com',
            'type' => User::TYPE_ADMIN,
            'link_name' => 'merchant-one',
        ]);
    }

    public function test_merchant_only_sees_their_own_employees()
    {
        // 1. Create two merchants
        $merchant1 = User::factory()->create(['type' => User::TYPE_ADMIN]);
        $merchant2 = User::factory()->create(['type' => User::TYPE_ADMIN]);

        // 2. Create employees under each merchant
        User::factory()->count(3)->create([
            'type' => User::TYPE_EMPLOYEE,
            'parent_id' => $merchant1->id
        ]);

        User::factory()->count(2)->create([
            'type' => User::TYPE_EMPLOYEE,
            'parent_id' => $merchant2->id
        ]);

        // 3. Merchant 1 logs in and checks their employees
        $response1 = $this->actingAs($merchant1)
            ->getJson('/api/admin/employees');

        $response1->assertStatus(200)
            ->assertJsonCount(3, 'data');

        // 4. Merchant 2 logs in and checks their employees
        $response2 = $this->actingAs($merchant2)
            ->getJson('/api/admin/employees');

        $response2->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_employee_can_register_under_specific_merchant_using_slug()
    {
        $merchant = User::factory()->create([
            'name' => 'Global Merchant',
            'link_name' => 'global-merchant',
            'type' => User::TYPE_ADMIN
        ]);

        $response = $this->postJson('/api/employee/register', [
            'name' => 'Company X',
            'email' => 'companyx@example.com',
            'phone_number' => '08012345678',
            'password' => 'password',
            'password_confirmation' => 'password',
            'merchant' => 'global-merchant',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.parent_id', $merchant->id);

        $this->assertDatabaseHas('users', [
            'email' => 'companyx@example.com',
            'parent_id' => $merchant->id,
        ]);
    }
}
