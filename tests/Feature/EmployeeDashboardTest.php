<?php

namespace Tests\Feature;

use App\Models\Payroll;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeeDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_access_dashboard_with_correct_data()
    {
        $employee = User::factory()->employee()->create(['name' => 'Demo']);
        $employee->wallet()->create([
            'balance' => 600000,
            'currency' => 'NGN',
            'account_number' => '0123456789',
            'bank_name' => 'Access Bank',
        ]);
        
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

    public function test_employee_can_add_detailed_staff()
    {
        $employee = User::factory()->employee()->create();

        $response = $this->actingAs($employee)
            ->postJson('/api/employee/staff', [
                'first_name' => 'Momoh',
                'last_name' => 'Philip',
                'email' => 'momoh@example.com',
                'phone_number' => '+2348168252201',
                'job_title' => 'Software Engineer',
                'department' => 'Engineering',
                'start_date' => '2026-05-30',
                'bank_name' => 'Access Bank',
                'account_number' => '0123456789',
                'account_name' => 'Momoh Philip',
                'salary' => 350000,
                'pfa_name' => 'Stanbic IBTC',
                'rsa_pin' => 'PEN123456789',
                'pension_employee_rate' => 8,
                'pension_employer_rate' => 10,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('staff.first_name', 'Momoh')
            ->assertJsonPath('staff.salary', 350000);
    }

    public function test_employee_can_list_staff_with_search()
    {
        $employee = User::factory()->employee()->create();
        User::factory()->staff()->create([
            'parent_id' => $employee->id,
            'name' => 'Momoh Philip',
            'email' => 'momoh@example.com',
        ]);
        User::factory()->staff()->create([
            'parent_id' => $employee->id,
            'name' => 'Demo Staff',
            'email' => 'staff@example.com',
        ]);

        $response = $this->actingAs($employee)
            ->getJson('/api/employee/staff?search=Momoh');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'staff')
            ->assertJsonPath('staff.0.name', 'Momoh Philip');
    }

    public function test_employee_can_get_wallet_details()
    {
        $employee = User::factory()->employee()->create();
        $wallet = $employee->wallet()->create([
            'balance' => 600000,
            'currency' => 'NGN',
            'account_number' => '0123456789',
            'bank_name' => 'Access Bank',
        ]);

        $wallet->logs()->create([
            'amount' => 600000,
            'type' => 'credit',
            'balance_before' => 0,
            'balance_after' => 600000,
            'description' => 'Topup',
            'metadata' => ['reference' => 'REF123'],
        ]);

        $response = $this->actingAs($employee)
            ->getJson('/api/employee/wallet');

        $response->assertStatus(200)
            ->assertJsonPath('available_balance', '₦600,000.00')
            ->assertJsonCount(1, 'transactions');
    }

    public function test_employee_can_update_staff()
    {
        $employee = User::factory()->employee()->create();
        $staff = User::factory()->staff()->create(['parent_id' => $employee->id]);

        $response = $this->actingAs($employee)
            ->putJson("/api/employee/staff/{$staff->id}", [
                'first_name' => 'Updated',
                'last_name' => 'Name',
                'salary' => 500000,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('staff.first_name', 'Updated')
            ->assertJsonPath('staff.salary', 500000);
    }

    public function test_employee_can_toggle_staff_status()
    {
        $employee = User::factory()->employee()->create();
        $staff = User::factory()->staff()->create(['parent_id' => $employee->id, 'is_active' => true]);

        $response = $this->actingAs($employee)
            ->postJson("/api/employee/staff/{$staff->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJsonPath('is_active', false);
    }

    public function test_employee_can_invite_staff()
    {
        $employee = User::factory()->employee()->create();
        $staff = User::factory()->staff()->create(['parent_id' => $employee->id, 'invitation_status' => 'Not invited']);

        $response = $this->actingAs($employee)
            ->postJson("/api/employee/staff/{$staff->id}/invite");

        $response->assertStatus(200)
            ->assertJsonPath('invitation_status', 'Activated');
    }

    public function test_employee_can_bulk_upload_staff()
    {
        $employee = User::factory()->employee()->create();
        
        $csvContent = "first_name,last_name,email,phone,salary,job_title,department\n";
        $csvContent .= "Bulk1,User1,bulk1@example.com,+2341,400000,Dev,Engineering\n";
        $csvContent .= "Bulk2,User2,bulk2@example.com,+2342,500000,PM,Product\n";

        $file = UploadedFile::fake()->createWithContent('staff.csv', $csvContent);

        $response = $this->actingAs($employee)
            ->postJson('/api/employee/staff/bulk-upload', [
                'file' => $file,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Successfully uploaded 2 staff members');

        $this->assertDatabaseHas('users', ['email' => 'bulk1@example.com']);
        $this->assertDatabaseHas('users', ['email' => 'bulk2@example.com']);
    }

    public function test_employee_can_list_salary_advances()
    {
        $employee = User::factory()->employee()->create();
        $staff = User::factory()->staff()->create(['parent_id' => $employee->id]);
        
        SalaryAdvance::create([
            'user_id' => $employee->id,
            'staff_id' => $staff->id,
            'amount' => 50000,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($employee)
            ->getJson('/api/employee/salary-advances');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'salary_advances');
    }

    public function test_employee_can_view_payroll_history()
    {
        $employee = User::factory()->employee()->create();
        Payroll::create([
            'user_id' => $employee->id,
            'description' => 'May 2026 Salary',
            'amount' => 323834,
            'staff_count' => 2,
            'status' => 'completed',
            'processed_at' => now(),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);

        $response = $this->actingAs($employee)
            ->getJson('/api/employee/payrolls');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'payroll_history')
            ->assertJsonPath('payroll_history.0.total_amount', '₦323,834.00');
    }

    public function test_employee_can_view_detailed_payroll_run()
    {
        $employee = User::factory()->employee()->create();
        $staff = User::factory()->staff()->create(['parent_id' => $employee->id, 'name' => 'Demo Staff']);
        
        $payroll = \App\Models\Payroll::create([
            'user_id' => $employee->id,
            'description' => 'May 2026 Salary',
            'amount' => 323834,
            'staff_count' => 1,
            'status' => 'completed',
            'processed_at' => now(),
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
        ]);

        \App\Models\Payslip::create([
            'user_id' => $staff->id,
            'payroll_id' => $payroll->id,
            'period' => 'May 2026',
            'gross_salary' => 350000,
            'pension' => 28000,
            'other_deductions' => 0,
            'net_salary' => 322000,
        ]);

        $response = $this->actingAs($employee)
            ->getJson("/api/employee/payrolls/{$payroll->id}");

        $response->assertStatus(200)
            ->assertJsonPath('payroll_run.summary.staff_count', 1)
            ->assertJsonPath('payroll_run.staff_payments.0.name', 'Demo Staff');
    }

    public function test_employee_can_manage_team()
    {
        $employee = User::factory()->employee()->create(['role' => 'Owner']);
        
        // Add team member
        $response = $this->actingAs($employee)
            ->postJson('/api/employee/team', [
                'name' => 'Finance Manager',
                'email' => 'finance@example.com',
                'role' => 'Finance',
            ]);

        $response->assertStatus(201);
        $memberId = $response->json('member.id');

        // List team
        $response = $this->actingAs($employee)
            ->getJson('/api/employee/team');
        $response->assertStatus(200)
            ->assertJsonCount(2, 'team_members'); // Owner + Finance

        // Update role
        $response = $this->actingAs($employee)
            ->putJson("/api/employee/team/{$memberId}/role", [
                'role' => 'Hr',
            ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $memberId, 'role' => 'Hr']);

        // Toggle status
        $response = $this->actingAs($employee)
            ->postJson("/api/employee/team/{$memberId}/toggle-status");
        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $memberId, 'is_active' => false]);
    }

    public function test_team_member_can_access_owner_data()
    {
        $owner = User::factory()->employee()->approved()->create();
        $owner->wallet()->create(['balance' => 5000, 'currency' => 'NGN']);
        
        $teamMember = User::create([
            'name' => 'Finance Specialist',
            'email' => 'finance@company.com',
            'role' => 'Finance',
            'type' => User::TYPE_EMPLOYEE,
            'parent_id' => $owner->id,
            'password' => Hash::make('password'),
            'is_approved' => true,
        ]);

        // Team member logs in and tries to see wallet
        $response = $this->actingAs($teamMember)
            ->getJson('/api/employee/wallet');

        $response->assertStatus(200)
            ->assertJsonPath('available_balance', '₦5,000.00');
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
        $employee = User::factory()->employee()->approved()->create([
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
