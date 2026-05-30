<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_unapproved_employee_cannot_login()
    {
        $employee = User::factory()->employee()->create([
            'email' => 'unapproved@example.com',
            'password' => 'password',
            'is_approved' => false,
        ]);

        $response = $this->postJson('/api/employee/login', [
            'email' => 'unapproved@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_approved_employee_can_login()
    {
        $employee = User::factory()->employee()->approved()->create([
            'email' => 'approved@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/employee/login', [
            'email' => 'approved@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['status', 'message', 'data' => ['token', 'user']]);
    }

    public function test_admin_can_approve_employee_and_create_wallet()
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'password',
        ]);

        $employee = User::factory()->employee()->create();

        $response = $this->actingAs($admin)
            ->postJson("/api/admin/employees/{$employee->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('data.is_approved', true);

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'is_approved' => true,
        ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $employee->id,
            'balance' => 0.00,
        ]);

        $wallet = Wallet::where('user_id', $employee->id)->first();
        $this->assertNotNull($wallet->account_number);
        $this->assertNotNull($wallet->bank_name);
        $this->assertNotNull($wallet->account_reference);
    }

    public function test_admin_can_list_employees()
    {
        $admin = User::factory()->admin()->create();
        User::factory()->employee()->count(3)->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/employees');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_can_view_employee_details()
    {
        $admin = User::factory()->admin()->create();
        $employee = User::factory()->employee()->create();

        $response = $this->actingAs($admin)
            ->getJson("/api/admin/employees/{$employee->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $employee->id);
    }
}
