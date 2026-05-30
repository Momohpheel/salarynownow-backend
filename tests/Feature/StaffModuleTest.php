<?php

namespace Tests\Feature;

use App\Models\Payslip;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_access_dashboard()
    {
        $employer = User::factory()->employee()->create(['company_name' => 'Test Company']);
        $staff = User::factory()->staff()->create([
            'parent_id' => $employer->id,
            'first_name' => 'Chiamaka',
            'salary' => 800000000,
        ]);

        $response = $this->actingAs($staff)
            ->getJson('/api/staff/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('greeting', 'Hello, Chiamaka 👋')
            ->assertJsonPath('net_salary.amount', '₦800,000,000.00');
    }

    public function test_staff_can_view_profile_and_update_bank()
    {
        $staff = User::factory()->staff()->create([
            'bank_name' => 'Old Bank',
            'account_number' => '1234567890',
        ]);

        $response = $this->actingAs($staff)
            ->getJson('/api/staff/profile');

        $response->assertStatus(200)
            ->assertJsonPath('bank_details.bank', 'Old Bank');

        // Update bank
        $response = $this->actingAs($staff)
            ->putJson('/api/staff/bank/update', [
                'bank_name' => 'Access Bank',
                'account_number' => '0058510230',
                'account_name' => 'CHIAMAKA OBIOHA',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id' => $staff->id,
            'bank_name' => 'Access Bank',
        ]);
    }

    public function test_staff_can_view_payslips()
    {
        $staff = User::factory()->staff()->create();
        Payslip::create([
            'user_id' => $staff->id,
            'period' => 'May 2026',
            'gross_salary' => 800000,
            'pension' => 64000,
            'net_salary' => 736000,
            'status' => Payslip::STATUS_DISBURSED,
        ]);

        $response = $this->actingAs($staff)
            ->getJson('/api/staff/payslips');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.net', '₦736,000.00');
    }

    public function test_staff_can_request_salary_advance()
    {
        $employer = User::factory()->employee()->create();
        $staff = User::factory()->staff()->create([
            'parent_id' => $employer->id,
            'salary' => 800000,
        ]);

        // Check eligibility
        $response = $this->actingAs($staff)
            ->getJson('/api/staff/salary-advance/eligibility');
        $response->assertStatus(200)
            ->assertJsonPath('max_advance_raw', 400000);

        // Submit request
        $response = $this->actingAs($staff)
            ->postJson('/api/staff/salary-advance', [
                'amount' => 100000,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('salary_advances', [
            'staff_id' => $staff->id,
            'amount' => 100000,
            'status' => 'pending',
        ]);
    }
}
