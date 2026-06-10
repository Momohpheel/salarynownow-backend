<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CompleteProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_can_complete_profile_and_auto_approve()
    {
        Storage::fake('public');

        $employee = User::factory()->employee()->create();

        $response = $this->actingAs($employee)
            ->postJson('/api/employee/complete-profile', [
                'company_name' => 'Test Company',
                'rc_number' => 'RC123456',
                'industry' => 'Technology',
                'company_address' => '123 Test Street',
                'number_of_staff' => 10,
                'bvn' => '12345678901',
                'cac_certificate' => UploadedFile::fake()->create('cac.pdf', 100),
                'director_id' => UploadedFile::fake()->image('id.jpg'),
                'utility_bill' => UploadedFile::fake()->image('bill.png'),
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.is_approved', true)
            ->assertJsonStructure(['data' => ['wallet' => ['account_number', 'bank_name']]]);

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'company_name' => 'Test Company',
            'is_approved' => true,
        ]);

        $this->assertDatabaseHas('wallets', [
            'user_id' => $employee->id,
        ]);

        Storage::disk('public')->assertExists($employee->fresh()->cac_certificate_path);
    }

    public function test_cannot_complete_profile_if_already_approved()
    {
        $employee = User::factory()->employee()->approved()->create();

        $response = $this->actingAs($employee)
            ->postJson('/api/employee/complete-profile', [
                'company_name' => 'Test Company',
                'rc_number' => 'RC123456',
                'industry' => 'Technology',
                'company_address' => '123 Test Street',
                'number_of_staff' => 10,
                'bvn' => '12345678901',
                'cac_certificate' => UploadedFile::fake()->create('cac.pdf', 100),
                'director_id' => UploadedFile::fake()->image('id.jpg'),
            ]);

        $response->assertStatus(400)
            ->assertJsonPath('message', 'Account is already approved.');
    }
}
