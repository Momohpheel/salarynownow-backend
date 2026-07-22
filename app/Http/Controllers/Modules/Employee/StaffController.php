<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Mail\StaffInvitation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;

class StaffController extends Controller
{
    public function store(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $request->validate([
            // Personal Information
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone_number' => ['required', 'string', 'max:20'],
            'job_title' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'dob' => ['nullable', 'date'],
            'state_of_origin' => ['nullable', 'string', 'max:255'],

            // Bank Details
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:255'],

            // Compensation
            'salary' => ['required', 'numeric', 'min:0'],

            // Pension Details
            // 'pf-name' => ['required', 'string', 'max:255'],
            // 'rsa_pin' => ['required', 'string', 'max:50'],
            // 'pension_employee_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            // 'pension_employer_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $password = '123456';
        $staff = User::create([
            'name' => $request->first_name . ' ' . $request->last_name,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            // 'password' => Hash::make(Str::random(12)), // Random password since they'll be invited
            'type' => User::TYPE_STAFF,
            'password' => Hash::make($password), // Random password since they'll be invited
            'parent_id' => $employerId,
            'job_title' => $request->job_title,
            'department' => $request->department,
            'start_date' => $request->start_date,
            'dob' => $request->dob,
            'state_of_origin' => $request->state_of_origin,
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_name' => $request->account_name,
            'salary' => $request->salary,
            'pfa_name' => $request->pfa_name,
            'rsa_pin' => $request->rsa_pin,
            'pension_employee_rate' => 0.0, //$request->pension_employee_rate,
            'pension_employer_rate' => 0.0, //$request->pension_employer_rate,
            'invitation_status' => 'Not invited',
            'is_approved' => true, // Staff added by employees are auto-approved for their own system
        ]);

        return $this->sendResponse($staff, 'Staff member added successfully', true, 201);
    }

    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $query = User::where('parent_id', $employerId)->staff();

        // Search by name, email, or phone
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone_number', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status !== 'All') {
            $query->where('invitation_status', $request->status);
        }

        $staff = $query->orderBy('created_at', 'desc')->get();

        $data = $staff->map(function($s) {
            return [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->phone_number ?? '-',
                'bank' => $s->bank_name ?? '-',
                'state_of_origin' => $s->state_of_origin ?? '-',
                'date_of_birth' => $s->dob ?? '-',
                'salary' => '₦' . number_format($s->salary, 2),
                'status' => $s->invitation_status,
                'is_active' => $s->is_active,
                'department' => $s->department ?? '-',
                'job_title' => $s->job_title ?? '-',
                'start_date' => $s->start_date ?? '-'
            ];
        });

        return $this->sendResponse($data, 'Staff list retrieved successfully');
    }

    public function update(Request $request, User $staff)
    {
        $employerId = $request->user()->getEmployerId();

        if ($staff->employer_id !== $employerId || $staff->type !== User::TYPE_STAFF) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class.',email,'.$staff->id],
            'phone_number' => ['sometimes', 'string', 'max:20'],
            'job_title' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'string', 'max:255'],
            'salary' => ['sometimes', 'numeric', 'min:0'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'state_of_origin' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $data = $request->only([
            'first_name', 'last_name', 'email', 'phone_number', 
            'job_title', 'department', 'salary', 'dob', 'state_of_origin'
        ]);

        if ($request->has('first_name') || $request->has('last_name')) {
            $data['name'] = ($request->first_name ?? $staff->first_name) . ' ' . ($request->last_name ?? $staff->last_name);
        }

        $staff->update($data);

        return $this->sendResponse($staff, 'Staff updated successfully');
    }

    public function toggleStatus(Request $request, User $staff)
    {
        $employerId = $request->user()->getEmployerId();

        if ($staff->parent_id !== $employerId || $staff->type !== User::TYPE_STAFF) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        $request->validate([
            'status' => ['required', 'string', 'in:Active,On Leave,Offboarded'],
        ]);

        $status = $request->status;

        $staff->update(['invitation_status' => $status]);

        return $this->sendResponse(['status' => $staff->invitation_status], 'Staff status updated successfully');
    }

    public function invite(Request $request, User $staff)
    {
        $employerId = $request->user()->getEmployerId();

        if ($staff->employer_id !== $employerId || $staff->type !== User::TYPE_STAFF) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        $employer = User::find($employerId);
        
        // Create a password reset token
        $token = Password::createToken($staff);
        
        // Generate the invite link (you can customize this based on your frontend URL)
        $inviteLink = config('app.frontend_url', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . urlencode($staff->email);
        
        // Send the email
        Mail::to($staff->email)->send(new StaffInvitation($staff, $employer, $inviteLink));
        
        // Update invitation status
        $staff->update(['invitation_status' => 'Invited']);

        return $this->sendResponse(['invitation_status' => $staff->invitation_status], "Invitation sent to {$staff->email}");
    }

    public function bulkUpload(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getPathname(), 'r');
        
        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return $this->sendError('The uploaded CSV file is empty.', null, 422);
        }

        $normalizedHeader = array_map(function ($column) {
            return strtolower(trim((string) $column));
        }, $header);

        $requiredColumns = [
            'first_name',
            'last_name',
            'email',
            'phone',
            'date_of_birth',
            'state_of_origin',
            'department',
            'role',
            'start_date',
            'employment_type',
            'bank_name',
            'account_number',
            'account_name',
            'gross_salary',
            'pension_employee',
            'pension_employer',
            'tax_deduction',
            'nhf',
            'net_salary',
        ];

        $missingColumns = array_diff($requiredColumns, $normalizedHeader);
        if (! empty($missingColumns)) {
            fclose($handle);
            return $this->sendError(
                'CSV header is invalid.',
                ['missing_columns' => array_values($missingColumns)],
                422
            );
        }

        $columnIndexes = array_flip($normalizedHeader);
        
        $summary = [
            'total_records' => 0,
            'successful_uploads' => 0,
            'failed_uploads' => 0,
            'errors' => [],
        ];

        $rowNumber = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if (count(array_filter($data, fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $summary['total_records']++;

            $rowData = [];
            foreach ($columnIndexes as $column => $index) {
                $rowData[$column] = trim((string) ($data[$index] ?? ''));
            }

            $errors = $this->validateRow($rowData);

            if (!empty($errors)) {
                $summary['failed_uploads']++;
                $summary['errors'][] = [
                    'row' => $rowNumber,
                    'errors' => $errors,
                ];
                continue;
            }

            if (User::where('email', $rowData['email'])->exists()) {
                $summary['failed_uploads']++;
                $summary['errors'][] = [
                    'row' => $rowNumber,
                    'errors' => ['email' => 'A user with this email already exists.'],
                ];
                continue;
            }

            User::create([
                'name' => $rowData['first_name'] . ' ' . $rowData['last_name'],
                'first_name' => $rowData['first_name'],
                'last_name' => $rowData['last_name'],
                'email' => $rowData['email'],
                'phone_number' => $rowData['phone'] ?? null,
                'dob' => $rowData['date_of_birth'] ?? null,
                'state_of_origin' => $rowData['state_of_origin'] ?? null,
                'department' => $rowData['department'] ?? 'General',
                'job_title' => $rowData['role'] ?? 'Staff',
                'role_id' => null, // Assuming role is not being set from CSV for now
                'start_date' => $rowData['start_date'] ?? null,
                'bank_name' => $rowData['bank_name'] ?? null,
                'account_number' => $rowData['account_number'] ?? null,
                'account_name' => $rowData['account_name'] ?? null,
                'salary' => $rowData['gross_salary'] ?? 0,
                'pension_employee_rate' => $rowData['pension_employee'] ?? 0,
                'pension_employer_rate' => $rowData['pension_employer'] ?? 0,
                'tax_deduction' => $rowData['tax_deduction'] ?? 0,
                'nhf' => $rowData['nhf'] ?? 0,
                'net_salary' => $rowData['net_salary'] ?? 0,
                'type' => User::TYPE_STAFF,
                'parent_id' => $employerId,
                'password' => Hash::make(Str::random(12)),
                'is_approved' => true,
                'invitation_status' => 'Not invited',
            ]);

            $summary['successful_uploads']++;
        }
        
        fclose($handle);

        return $this->sendResponse($summary, "Bulk upload process completed.");
    }

    private function validateRow(array $data): array
    {
        $validator = Validator::make($data, [
            'gross_salary' => ['required', 'numeric', 'min:0'],
            'net_salary' => ['required', 'numeric', 'min:0', function ($attribute, $value, $fail) use ($data) {
                if (isset($data['gross_salary']) && $value > $data['gross_salary']) {
                    $fail('Net salary cannot be greater than gross salary.');
                }
            }],
            'pension_employee' => ['nullable', 'numeric'],
            'pension_employer' => ['nullable', 'numeric'],
            'tax_deduction' => ['nullable', 'numeric', 'min:0'],
            'nhf' => ['nullable', 'numeric', 'min:0'], // Basic validation for now
        ]);

        return $validator->fails() ? $validator->errors()->toArray() : [];
    }
}
