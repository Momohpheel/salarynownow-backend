<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;

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

            // Bank Details
            'bank_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:255'],

            // Compensation
            'salary' => ['required', 'numeric', 'min:0'],

            // Pension Details
            // 'pfa_name' => ['required', 'string', 'max:255'],
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
            'bank_name' => $request->bank_name,
            'account_number' => $request->account_number,
            'account_name' => $request->account_name,
            'salary' => $request->salary,
            'pfa_name' => $request->pfa_name,
            'rsa_pin' => $request->rsa_pin,
            'pension_employee_rate' => 8.0, //$request->pension_employee_rate,
            'pension_employer_rate' => 8.0, //$request->pension_employer_rate,
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
                'salary' => '₦' . number_format($s->salary, 2),
                'status' => $s->invitation_status,
                'is_active' => $s->is_active,
                'department' => $s->department ?? '-',
                'job_title' => $s->job_title ?? '-',
                'start_date' => $s->start_date->diffForHumans() ?? '-',
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
            'email' => ['sometimes', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class.',email,'.$staff->id],
            'phone_number' => ['sometimes', 'string', 'max:20'],
            'job_title' => ['sometimes', 'string', 'max:255'],
            'department' => ['sometimes', 'string', 'max:255'],
            'salary' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $data = $request->only([
            'first_name', 'last_name', 'email', 'phone_number', 
            'job_title', 'department', 'salary'
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

        $staff->update(['is_active' => !$staff->is_active]);

        return $this->sendResponse(['is_active' => $staff->is_active], 'Staff status updated successfully');
    }

    public function invite(Request $request, User $staff)
    {
        $employerId = $request->user()->getEmployerId();

        if ($staff->parent_id !== $employerId || $staff->type !== User::TYPE_STAFF) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        // Simulate sending mail
        $staff->update(['invitation_status' => 'Activated']); // For demo purposes, we'll mark as activated

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
        
        // Skip header
        $header = fgetcsv($handle);
        
        $count = 0;
        while (($data = fgetcsv($handle)) !== false) {
            // Simple mapping: first_name, last_name, email, phone, salary, job_title, department
            if (count($data) < 3) continue;

            User::create([
                'name' => $data[0] . ' ' . $data[1],
                'first_name' => $data[0],
                'last_name' => $data[1],
                'email' => $data[2],
                'phone_number' => $data[3] ?? null,
                'salary' => $data[4] ?? 0,
                'job_title' => $data[5] ?? 'Staff',
                'department' => $data[6] ?? 'General',
                'type' => User::TYPE_STAFF,
                'parent_id' => $employerId,
                'password' => Hash::make(Str::random(12)),
                'is_approved' => true,
                'invitation_status' => 'Not invited',
            ]);
            $count++;
        }
        
        fclose($handle);

        return $this->sendResponse(null, "Successfully uploaded {$count} staff members");
    }
}
