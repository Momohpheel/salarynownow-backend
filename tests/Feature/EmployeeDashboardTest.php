<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_access_dashboard_with_correct_data()
    {
        $employee = User::factory()->employee()->create(['name' => 'Demo']);
        
        // Create 2 staff members
        User::factory()->staff()->count(2)->create(['parent_id' => $employee->id]);
        
        // Create a recent payroll
        Payroll::create([
            'user_id' => $employee->id,
            'description' => 'May 2026 Salary',
            'amount' => 323834.00,
            'staff_count' => 2,
            'status' => 'completed',
            'processed_at' => now()->subDay(),
        ]);

        // Create 1 pending advance
        SalaryAdvance::create([
            'user_id' => $employee->id,
            'staff_id' => User::factory()->staff()->create(['parent_id' => $employee->id])->id,
            'amount' => 50000.00,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($employee)
            ->getJson('/api/employee/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('summary.total_staff.value', 3) // 2 + 1 from advance staff
            ->assertJsonPath('summary.pending_advances.value', 1)
            ->assertJsonFragment(['description' => 'May 2026 Salary']);
    }

    public function test_employee_logout()
    {
        $employee = User::factory()->employee()->create();
        $token = $employee->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/logout');

        $response->assertStatus(200);
        $this->assertEmpty($employee->tokens);
    }

    public function test_forgot_password_flow()
    {
        $employee = User::factory()->employee()->create([
            'email' => 'test@example.com',
            'password' => 'old-password',
        ]);

        // 1. Send reset link
        $response = $this->postJson('/api/employee/forgot-password', ['email' => 'test@example.com']);
        $response->assertStatus(200);

        // 2. Reset password
        $response = $this->postJson('/api/employee/reset-password', [
            'email' => 'test@example.com',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
            'token' => 'simulated-token',
        ]);

        $response->assertStatus(200);
        
        // 3. Verify login with new password
        $response = $this->postJson('/api/employee/login', [
            'email' => 'test@example.com',
            'password' => 'new-password',
        ]);
        $response->assertStatus(200);
    }
}
