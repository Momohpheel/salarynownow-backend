<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Mail\StaffAdded;
use App\Mail\StaffInvitation;
use App\Models\User;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class StaffController extends Controller
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    public function store(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $employer = User::find($employerId);

        $request->validate([
            // Personal Information
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->where(fn ($query) => $query->where('type', User::TYPE_STAFF))],
            'phone_number' => ['required', 'string', 'max:20'],
            'job_title' => ['required', 'string', 'max:255'],
            'department' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date'],
            'date_of_birth' => ['nullable', 'date'],
            'state_of_origin' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['required', 'string', 'max:255'],

            // Bank Details
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:255'],

            // Compensation
            'gross_salary' => ['required', 'numeric', 'min:0'],
            'net_salary' => ['required', 'numeric', 'min:0'],
            'tax_deduction' => ['required', 'numeric', 'min:0'],
            'pension_employee' => ['required', 'numeric', 'min:0'],
            'pension_employer' => ['required', 'numeric', 'min:0'],
            'nhf' => ['required', 'numeric', 'min:0'],
        ]);

        $password = Str::random(12);
        $staff = User::create([
            'name' => $request->first_name . ' ' . $request->last_name,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'password' => Hash::make($password), // Random password since they'll be invited
            'type' => User::TYPE_STAFF,
            'parent_id' => $employerId,
            'job_title' => $request->job_title,
            'department' => $request->department,
            'start_date' => $request->start_date,
            'dob' => $request->date_of_birth,
            'state_of_origin' => $request->state_of_origin,
            'employment_type' => $request->employment_type,
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_name' => $request->account_name,
            'salary' => $request->gross_salary,
            'net_salary' => $request->net_salary,
            'pension_employee_rate' => $request->pension_employee,
            'pension_employer_rate' => $request->pension_employer,
            'tax_deduction' => $request->tax_deduction,
            'nhf' => $request->nhf,
            'invitation_status' => 'Not invited',
            'status' => 'Active',
            'is_approved' => true, // Staff added by employees are auto-approved for their own system
        ]);

        Mail::to($staff->email)->send(new StaffAdded(
            $staff,
            $employer,
            $password,
            $this->getStaffLoginUrl()
        ));

        return $this->sendResponse($staff, 'Staff member added successfully', true, 201);
    }

    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();
        $query = User::where('parent_id', $employerId)
            ->staff()
            ->with([
                'staffAdvances',
                'payslips.payroll',
            ]);

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
                'account_number' => $s->account_number ?? '-',
                'bank_name' => $s->bank_name ?? '-',
                'state_of_origin' => $s->state_of_origin ?? '-',
                'date_of_birth' => $s->dob ? Carbon::parse($s->dob)->format('d-m-Y') : '-',
                'salary' => '₦' . number_format($s->salary, 2),
                'status' => $s->status,
                'is_active' => $s->is_active,
                'department' => $s->department ?? '-',
                'job_title' => $s->job_title ?? '-',
                'start_date' => $s->start_date ? Carbon::parse($s->start_date)->format('d-m-Y') : '-',
                'payrolls' => $s->payslips->map(function ($payslip) {
                    return [
                        'payslip_id' => $payslip->id,
                        'payslip_reference' => $payslip->reference,
                        'payroll_id' => $payslip->payroll?->id,
                        'payroll_reference' => $payslip->payroll?->reference,
                        'period' => $payslip->period,
                        'gross_salary' => (float) $payslip->gross_salary,
                        'net_salary' => (float) $payslip->net_salary,
                        'status' => $payslip->status,
                        'description' => $payslip->payroll?->description,
                        'processed_at' => $payslip->payroll?->processed_at?->toISOString(),
                    ];
                })->values(),
                'advances' => $s->staffAdvances->map(function ($advance) {
                    return [
                        'id' => $advance->id,
                        'amount' => (float) $advance->amount,
                        'status' => $advance->status,
                        'created_at' => $advance->created_at?->toISOString(),
                        'updated_at' => $advance->updated_at?->toISOString(),
                    ];
                })->values(),
            ];
        });

        return $this->sendResponse($data, 'Staff list retrieved successfully');
    }

    public function update(Request $request, User $staff)
    {
        $employerId = $request->user()->getEmployerId();

        if ($staff->parent_id !== $employerId || $staff->type !== User::TYPE_STAFF) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        $request->validate([
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->where(fn ($query) => $query->where('type', User::TYPE_STAFF))->ignore($staff->id)],
            'phone_number' => ['sometimes', 'string', 'max:20'],
            'job_title' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'string', 'max:255'],
            'salary' => ['sometimes', 'numeric', 'min:0'],
            'dob' => ['sometimes', 'nullable', 'date'],
            'state_of_origin' => ['sometimes', 'nullable', 'string', 'max:255'],
             'bank_name' => ['sometimes','nullable', 'string', 'max:255'],
            'account_number' => ['sometimes','nullable', 'string', 'max:20'],
            'account_name' => ['sometimes','nullable', 'string', 'max:255'],
        ]);

        $data = $request->only([
            'first_name', 'last_name', 'email', 'phone_number', 'bank_name', 'account_number', 'account_name',
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
        $staff->update(['status' => $status]);

        return $this->sendResponse(['status' => $staff->status], 'Staff status updated successfully');
    }

    public function invite(Request $request, User $staff)
    {
        $employerId = $request->user()->getEmployerId();

        if ($staff->parent_id !== $employerId || $staff->type !== User::TYPE_STAFF) {
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
        $employer = User::find($employerId);

        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xls,xlsx', 'max:2048'],
        ]);

        $collection = Excel::toCollection(new \stdClass(), $request->file('file'));

        if ($collection->isEmpty() || $collection->first()->isEmpty()) {
            return $this->sendError('The uploaded file is empty.', null, 422);
        }

        $header = $collection->first()->first()->toArray();

        $normalizedHeader = array_map(function ($column) {
            return strtolower(trim((string) $column));
        }, $header);

        $requiredColumns = [
            'first_name',
            'last_name',
            'email',
            'phone',
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
    
            return $this->sendError(
                'Header is invalid.',
                ['missing_columns' => array_values($missingColumns)],
                422
            );
        }

        $columnIndexes = array_flip($normalizedHeader);
        
        $banks = $this->sarepayService->getBanks();
        $bankNames = collect($banks)->pluck('name')->map(function ($name) {
            return $this->normalizeBankName($name);
        })->toArray();

        $summary = [
            'total_records' => 0,
            'successful_uploads' => 0,
            'failed_uploads' => 0,
            'errors' => [],
        ];

        $rows = $collection->first()->slice(1);
        $rowNumber = 1;
        foreach ($rows as $data) {
            $rowNumber++;
            if (count(array_filter($data->toArray(), fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }

            $summary['total_records']++;

            $rowData = [];
            foreach ($columnIndexes as $column => $index) {
                $rowData[$column] = trim((string) ($data[$index] ?? ''));
            }

            if (!in_array($this->normalizeBankName($rowData['bank_name']), $bankNames)) {
                $summary['failed_uploads']++;
                $summary['errors'][] = [
                    'row' => $rowNumber,
                    'errors' => ['bank_name' => 'The provided bank name does not exist.'],
                ];
                continue;
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

            if (User::where('email', $rowData['email'])->where('type', User::TYPE_STAFF)->exists()) {
                $summary['failed_uploads']++;
                $summary['errors'][] = [
                    'row' => $rowNumber,
                    'errors' => ['email' => 'A staff with this email already exists.'],
                ];
                continue;
            }

            $formattedStartDate = $this->formatDate($rowData['start_date']);
            $formattedDob = !empty($rowData['date_of_birth']) ? $this->formatDate($rowData['date_of_birth']) : null;
            
            $password = Str::random(12);

            $staff = User::create([
                'name' => $rowData['first_name'] . ' ' . $rowData['last_name'],
                'first_name' => $rowData['first_name'],
                'last_name' => $rowData['last_name'],
                'email' => $rowData['email'],
                'phone_number' => $rowData['phone'] ?? null,
                'dob' => $formattedDob,
                'state_of_origin' => $rowData['state_of_origin'] ?? null,
                'department' => $rowData['department'] ?? 'General',
                'job_title' => $rowData['role'] ?? 'Staff',
                'role_id' => null, // Assuming role is not being set from CSV for now
                'start_date' => $formattedStartDate,
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
                'password' => Hash::make($password),
                'is_approved' => true,
                'invitation_status' => 'Not invited',
                'status' => 'Active',
            ]);

            Mail::to($staff->email)->send(new StaffAdded(
                $staff,
                $employer,
                $password,
                $this->getStaffLoginUrl()
            ));

            $summary['successful_uploads']++;
        }
        


        return $this->sendResponse($summary, "Bulk upload process completed.");
    }

    private function formatDate(string $date): ?string
    {
        $date = trim($date);

        if ($date === '') {
            return null;
        }

        $supportedFormats = [
            'd/m/Y',
            'Y-m-d',
            'd-m-Y',
            'Y/m/d',
            'd.m.Y',
            'Y.m.d',
            'd M Y',
            'd F Y',
            'M d Y',
            'F d Y',
        ];

        foreach ($supportedFormats as $format) {
            try {
                $parsedDate = Carbon::createFromFormat($format, $date);

                if ($parsedDate !== false && $parsedDate->format($format) === $date) {
                    return $parsedDate->format('Y-m-d');
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function validateRow(array $data): array
    {
        $validator = Validator::make($data, [
            'account_number' => ['required', 'digits:10'],
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
            'start_date' => ['required'],
            'date_of_birth' => ['nullable'],
        ]);

        $errors = $validator->fails() ? $validator->errors()->toArray() : [];

        if (!empty($data['start_date'])) {
            if ($this->formatDate($data['start_date']) === null) {
                $errors['start_date'][] = 'The start_date is not a valid date.';
            }
        }

        if (!empty($data['date_of_birth'])) {
            if ($this->formatDate($data['date_of_birth']) === null) {
                $errors['date_of_birth'][] = 'The date_of_birth is not a valid date.';
            }
        }

        return $errors;
    }

    private function getStaffLoginUrl(): string
    {
        return config('app.frontend_url', 'https://salarynownow.com') . '/login';
    }

    private function normalizeBankName(string $bankName): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $bankName)));
    }
}
