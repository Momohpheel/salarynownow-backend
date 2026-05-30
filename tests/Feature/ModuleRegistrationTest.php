<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ModuleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_register_with_all_details()
    {
        Storage::fake('public');

        $response = $this->postJson('/api/employee/register', [
            // Step 1
            'name' => 'John Employee',
            'email' => 'john@example.com',
            'phone_number' => '+2348012345678',
            'password' => 'password',
            'password_confirmation' => 'password',
            
            // Step 2
            'company_name' => 'Acme Corp',
            'rc_number' => 'RC-12345',
            'industry' => 'Tech',
            'company_address' => '123 Street, Lagos',
            'number_of_staff' => 50,
            
            // Step 3
            'cac_certificate' => UploadedFile::fake()->create('cac.pdf', 100),
            'director_id' => UploadedFile::fake()->image('id.jpg'),
            'utility_bill' => UploadedFile::fake()->image('bill.png'),
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.type', User::TYPE_EMPLOYEE)
            ->assertJsonPath('user.company_name', 'Acme Corp');

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'type' => User::TYPE_EMPLOYEE,
            'company_name' => 'Acme Corp',
        ]);
        
        Storage::disk('public')->assertExists(User::where('email', 'john@example.com')->first()->cac_certificate_path);
    }

    public function test_employee_can_login()
    {
        $employee = User::factory()->employee()->create([
            'email' => 'employee@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/employee/login', [
            'email' => 'employee@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_partner_can_login()
    {
        $partner = User::factory()->partner()->create([
            'email' => 'partner@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/partner/login', [
            'email' => 'partner@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_staff_can_login()
    {
        $staff = User::factory()->staff()->create([
            'email' => 'staff@example.com',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/staff/login', [
            'email' => 'staff@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'user']);
    }

    public function test_wrong_user_type_cannot_login_to_different_module()
    {
        $employee = User::factory()->employee()->create([
            'email' => 'employee@example.com',
            'password' => 'password',
        ]);

        // Employee trying to login as staff
        $response = $this->postJson('/api/staff/login', [
            'email' => 'employee@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(422);
    }

    public function test_partner_can_register()
    {
        $response = $this->postJson('/api/partner/register', [
            'name' => 'Jane Partner',
            'email' => 'jane@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.type', User::TYPE_PARTNER);

        $this->assertDatabaseHas('users', [
            'email' => 'jane@example.com',
            'type' => User::TYPE_PARTNER,
        ]);
    }

    public function test_employee_can_add_staff()
    {
        $employee = User::factory()->employee()->create();

        $response = $this->actingAs($employee)
            ->postJson('/api/employee/staff', [
                'name' => 'Staff Member',
                'email' => 'staff@example.com',
                'password' => 'password',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('staff.type', User::TYPE_STAFF)
            ->assertJsonPath('staff.parent_id', $employee->id);

        $this->assertDatabaseHas('users', [
            'email' => 'staff@example.com',
            'type' => User::TYPE_STAFF,
            'parent_id' => $employee->id,
        ]);
    }

    public function test_employee_can_list_their_staff()
    {
        $employee = User::factory()->employee()->create();
        User::factory()->staff()->count(3)->create(['parent_id' => $employee->id]);
        
        // Create another staff for a different employee to ensure scoping works
        User::factory()->staff()->create();

        $response = $this->actingAs($employee)
            ->getJson('/api/employee/staff');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'staff');
    }
}
