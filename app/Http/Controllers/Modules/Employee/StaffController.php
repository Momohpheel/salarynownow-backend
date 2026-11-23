<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Mail\StaffAdded;
use App\Mail\StaffDeletionRequested;
use App\Mail\StaffInvitation;
use App\Models\Notification;
use App\Models\StaffDeletionRequest;
use App\Models\User;
use App\Services\Sarepay\SarepayService;
use App\Traits\ResolvesBusinessContext;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class StaffController extends Controller
{
    use ResolvesBusinessContext;

    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    public function store(Request $request)
    {
        $singleBusinessId = $this->requireSingleBusinessScope($request, $request->user());
        $employer = User::find($singleBusinessId);

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
            'parent_id' => $singleBusinessId,
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
        $scope = $this->resolveBusinessScope($request, $request->user());
        $isAll = $scope->mode === 'all';

        // Columns must be table-qualified: the "all" branch below self-joins
        // `users` as `employer_users`, so bare `parent_id`/`type` are ambiguous.
        $query = User::query()
            ->whereIn('users.parent_id', $scope->business_ids)
            ->where('users.type', User::TYPE_STAFF)
            ->with([
                'staffAdvances',
                'payslips.payroll',
            ]);

        if ($isAll) {
            $query->join('users as employer_users', 'employer_users.id', '=', 'users.parent_id')
                ->addSelect('users.*', 'employer_users.company_name as company_name');
        }

        // Search by name, email, or phone
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('users.name', 'like', "%{$search}%")
                  ->orWhere('users.email', 'like', "%{$search}%")
                  ->orWhere('users.phone_number', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->has('status') && $request->status !== 'All') {
            $query->where('users.invitation_status', $request->status);
        }

        // Only show soft-deleted staff when explicitly requested (for restore).
        if ($request->boolean('trashed')) {
            $query->onlyTrashed();
        }

        $staff = $query->orderBy('users.created_at', 'desc')->get();

        $data = $staff->map(function($s) use ($isAll) {
            $row = [
                'id' => $s->id,
                'name' => $s->name,
                'email' => $s->email,
                'phone' => $s->phone_number ?? '-',
                'account_number' => $s->account_number ?? '-',
                'bank_name' => $s->bank_name ?? '-',
                'state_of_origin' => $s->state_of_origin ?? '-',
                'date_of_birth' => $s->dob ? Carbon::parse($s->dob)->format('d-m-Y') : '-',
                'salary' => '₦' . number_format($s->net_salary, 2),
                'status' => $s->status,
                'is_active' => $s->is_active,
                'is_deleted' => method_exists($s, 'trashed') ? $s->trashed() : false,
                'deleted_at' => optional($s->deleted_at)->toISOString(),
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
                        'failure_reason' => Schema::hasColumn('payslips', 'failure_reason') ? ($payslip->failure_reason ?? null) : null,
                        'failure_code' => Schema::hasColumn('payslips', 'failure_code') ? ($payslip->failure_code ?? null) : null,
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
            if ($isAll) {
                $row['company_name'] = $s->company_name ?? null;
            }
            return $row;
        });

        return $this->sendResponse($data, 'Staff list retrieved successfully');
    }

    public function update(Request $request, User $staff)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());

        if (!in_array((int) $staff->parent_id, $scope->business_ids, true) || $staff->type !== User::TYPE_STAFF) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        // An already-set account number is the salary payout destination, so
        // changing it is sensitive: only the business owner may do so. The new
        // number is re-verified against Sarepay by the bank/verify endpoint
        // before it is saved here.
        $oldAccountNumber = (string) ($staff->account_number ?? '');
        $oldBankName = (string) ($staff->bank_name ?? '');
        $incomingAccountNumber = $request->has('account_number')
            ? trim((string) $request->account_number)
            : $oldAccountNumber;
        $isBankChange = $oldAccountNumber !== ''
            && $incomingAccountNumber !== ''
            && $incomingAccountNumber !== $oldAccountNumber;

        if ($isBankChange && ! $this->isBusinessOwner($request->user())) {
            return $this->sendError(
                "Only the business owner can change a staff member's account number. Please ask the account owner to update it.",
                null,
                403,
            );
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

        if ($isBankChange) {
            // Re-verify the new payout destination with Sarepay before saving so
            // a client cannot swap the account number without verification.
            $targetBankName = $request->has('bank_name') && $request->bank_name !== null
                ? trim((string) $request->bank_name)
                : $oldBankName;

            $verifiedName = $this->verifyAccountWithSarepay($incomingAccountNumber, $targetBankName, $staff);
            if ($verifiedName === null) {
                return $this->sendError(
                    'We could not verify the new account number with the bank. Please check the account number and bank, then try again.',
                    null,
                    422,
                );
            }

            // The bank is the source of truth for the account name.
            $data['account_name'] = $verifiedName;
        }

        $staff->update($data);

        if ($isBankChange) {
            Log::info('Staff bank account changed', [
                'staff_id' => $staff->id,
                'business_id' => $staff->parent_id,
                'changed_by' => $request->user()->id,
                'old_account_number' => $oldAccountNumber,
                'old_bank_name' => $oldBankName,
                'new_account_number' => (string) $staff->account_number,
                'new_bank_name' => (string) $staff->bank_name,
                'ip' => $request->ip(),
            ]);
        }

        return $this->sendResponse($staff, 'Staff updated successfully');
    }

    /**
     * True when the acting user is the business owner (not a team member).
     * Team members carry an employer_id pointing at the owner, so their own id
     * differs from getEmployerId().
     */
    private function isBusinessOwner(User $user): bool
    {
        return (int) $user->id === (int) $user->getEmployerId();
    }

    /**
     * Whether the user may approve (or reject/restore) staff deletions. Owners,
     * the "admin" role, and holders of the "approve_staff_deletion" permission
     * qualify; everyone else must raise a request for one of them to action.
     */
    private function userCanApproveStaffDeletion(User $user): bool
    {
        if ($this->isBusinessOwner($user)) {
            return true;
        }

        if ((bool) ($user->is_admin ?? false)) {
            return true;
        }

        try {
            if ($user->hasRole('admin')) {
                return true;
            }
        } catch (\Throwable) {
        }

        try {
            if ($user->hasPermissionTo('approve_staff_deletion')) {
                return true;
            }
        } catch (\Throwable) {
        }

        return false;
    }

    private function softDeleteStaff(User $staff): void
    {
        // Revoke any active sessions so the removed staff can no longer sign in.
        try {
            $staff->tokens()->delete();
        } catch (\Throwable) {
        }

        $staff->delete();
    }

    /**
     * Notify everyone who can approve staff deletions that a request is waiting
     * for their sign-off (in-app notification + email).
     */
    private function notifyStaffDeletionApprovers(User $requester, User $staff, StaffDeletionRequest $deletionRequest): void
    {
        try {
            $candidates = User::query()
                ->where(function ($q) use ($staff) {
                    $q->where('id', $staff->parent_id)
                        ->orWhere('employer_id', $staff->parent_id);
                })
                ->get();

            $notified = [];
            foreach ($candidates as $candidate) {
                $cid = (int) $candidate->id;
                if ($cid === (int) $requester->id || isset($notified[$cid])) {
                    continue;
                }
                if (! $this->userCanApproveStaffDeletion($candidate)) {
                    continue;
                }
                $notified[$cid] = true;

                Notification::notify($candidate, [
                    'category' => 'staff_deletion',
                    'type' => 'staff_deletion_requested',
                    'title' => 'Staff deletion awaiting your approval',
                    'body' => sprintf(
                        '%s requested to remove %s from your staff list. Review and approve to proceed.',
                        $requester->name ?? 'A team member',
                        $staff->name ?? 'a staff member'
                    ),
                    'icon' => 'clock',
                    'deep_link' => '/staff',
                    'metadata' => ['staff_id' => $staff->id, 'request_id' => $deletionRequest->id],
                ]);

                if (! empty($candidate->email)) {
                    try {
                        Mail::to($candidate->email)->send(
                            new StaffDeletionRequested($staff, $requester, $candidate->name, $deletionRequest->reason)
                        );
                    } catch (\Throwable $e) {
                        Log::warning('Failed to email staff deletion approver ' . $candidate->id . ': ' . $e->getMessage());
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify staff deletion approvers: ' . $e->getMessage());
        }
    }

    /**
     * Re-verify a payout account number with Sarepay. Returns the resolved
     * account name, or null when the bank code cannot be determined or the
     * account does not verify.
     */
    private function verifyAccountWithSarepay(string $accountNumber, string $bankName, User $staff): ?string
    {
        $bankCode = $this->resolveBankCodeForName($bankName);
        if ($bankCode === null) {
            Log::warning('Bank code not found while re-verifying staff account', [
                'staff_id' => $staff->id,
                'bank_name' => $bankName,
            ]);
            return null;
        }

        try {
            $result = $this->sarepayService->validateAccount($accountNumber, $bankCode);
        } catch (\Throwable $e) {
            Log::warning('Sarepay account re-verification error for staff ' . $staff->id . ': ' . $e->getMessage());
            return null;
        }

        $accountName = $result->data->account_name ?? null;

        return ($result->success ?? false) && !empty($accountName)
            ? (string) $accountName
            : null;
    }

    /**
     * Resolve a Sarepay bank code from a bank name (case/whitespace-insensitive).
     */
    private function resolveBankCodeForName(string $bankName): ?string
    {
        $target = $this->normalizeBankName($bankName);
        if ($target === '') {
            return null;
        }

        try {
            $banks = $this->sarepayService->getBanks();
        } catch (\Throwable $e) {
            Log::warning('Failed to fetch banks for account re-verification: ' . $e->getMessage());
            return null;
        }

        foreach ($banks as $bank) {
            $name = is_array($bank) ? ($bank['name'] ?? null) : ($bank->name ?? null);
            $code = is_array($bank) ? ($bank['code'] ?? null) : ($bank->code ?? null);
            if ($name !== null && $code !== null && $this->normalizeBankName((string) $name) === $target) {
                return (string) $code;
            }
        }

        return null;
    }

    public function destroy(Request $request, User $staff)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());
        $actor = $request->user();

        if (!in_array((int) $staff->parent_id, $scope->business_ids, true) || $staff->type !== User::TYPE_STAFF) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        if (!Schema::hasColumn('users', 'deleted_at')) {
            return $this->sendError('Staff deletion is unavailable. Please run pending migrations.', null, 422);
        }

        // An approver (owner/admin or a holder of "approve_staff_deletion") can
        // remove the staff member immediately.
        if ($this->userCanApproveStaffDeletion($actor)) {
            $this->softDeleteStaff($staff);

            return $this->sendResponse(null, 'Staff member deleted successfully');
        }

        // Everyone else raises a request that an approver must sign off first.
        if (Schema::hasTable('staff_deletion_requests')) {
            $existing = StaffDeletionRequest::query()
                ->where('staff_id', $staff->id)
                ->where('status', StaffDeletionRequest::STATUS_PENDING)
                ->first();

            if ($existing) {
                return $this->sendError('A deletion request for this staff member is already awaiting approval.', null, 422);
            }
        }

        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        if (!Schema::hasTable('staff_deletion_requests')) {
            return $this->sendError('Staff deletion requests are unavailable. Please run pending migrations.', null, 422);
        }

        $deletionRequest = StaffDeletionRequest::create([
            'staff_id' => $staff->id,
            'employer_id' => (int) $staff->parent_id,
            'requested_by' => $actor->id,
            'reason' => $validated['reason'] ?? null,
            'status' => StaffDeletionRequest::STATUS_PENDING,
        ]);

        $this->notifyStaffDeletionApprovers($actor, $staff, $deletionRequest);

        return $this->sendResponse([
            'pending_approval' => true,
            'request_id' => $deletionRequest->id,
        ], 'Deletion request submitted. It will take effect once an approver reviews it.');
    }

    /**
     * Restore a soft-deleted staff member.
     */
    public function restore(Request $request, $id)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());

        if (!Schema::hasColumn('users', 'deleted_at')) {
            return $this->sendError('Staff restore is unavailable. Please run pending migrations.', null, 422);
        }

        if (! $this->userCanApproveStaffDeletion($request->user())) {
            return $this->sendError('You do not have permission to restore staff members.', null, 403);
        }

        $staff = User::withTrashed()
            ->where('type', User::TYPE_STAFF)
            ->whereIn('parent_id', $scope->business_ids)
            ->find($id);

        if (! $staff || ! $staff->trashed()) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        $staff->restore();

        // Close out any pending deletion request that led to this removal.
        if (Schema::hasTable('staff_deletion_requests')) {
            StaffDeletionRequest::query()
                ->where('staff_id', $staff->id)
                ->where('status', StaffDeletionRequest::STATUS_PENDING)
                ->update([
                    'status' => StaffDeletionRequest::STATUS_REJECTED,
                    'rejected_at' => now(),
                ]);
        }

        return $this->sendResponse(['id' => $staff->id], 'Staff member restored successfully');
    }

    /**
     * List pending staff-deletion requests awaiting an approver.
     */
    public function deletionRequests(Request $request)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());

        if (!Schema::hasTable('staff_deletion_requests')) {
            return $this->sendResponse([], 'No pending deletion requests');
        }

        $requests = StaffDeletionRequest::query()
            ->whereIn('employer_id', $scope->business_ids)
            ->where('status', StaffDeletionRequest::STATUS_PENDING)
            ->with(['staff', 'requester'])
            ->orderByDesc('created_at')
            ->get()
            ->map(function (StaffDeletionRequest $r) {
                return [
                    'id' => $r->id,
                    'staff_id' => $r->staff_id,
                    'staff_name' => $r->staff?->name
                        ?? trim((string) ($r->staff?->first_name . ' ' . $r->staff?->last_name)),
                    'staff_email' => $r->staff?->email,
                    'job_title' => $r->staff?->job_title,
                    'department' => $r->staff?->department,
                    'reason' => $r->reason,
                    'requested_by' => $r->requester?->name,
                    'requested_by_email' => $r->requester?->email,
                    'created_at' => $r->created_at?->toISOString(),
                ];
            });

        return $this->sendResponse($requests, 'Pending deletion requests retrieved');
    }

    /**
     * Approve a pending deletion request, removing the staff member.
     */
    public function approveDeletion(Request $request, $id)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());
        $actor = $request->user();

        if (!Schema::hasTable('staff_deletion_requests')) {
            return $this->sendError('Staff deletion requests are unavailable. Please run pending migrations.', null, 422);
        }

        if (! $this->userCanApproveStaffDeletion($actor)) {
            return $this->sendError('You do not have permission to approve staff deletions.', null, 403);
        }

        $deletionRequest = StaffDeletionRequest::whereIn('employer_id', $scope->business_ids)->findOrFail($id);

        if ($deletionRequest->status !== StaffDeletionRequest::STATUS_PENDING) {
            return $this->sendError('This deletion request has already been resolved.', null, 422);
        }

        $staff = User::withTrashed()->find($deletionRequest->staff_id);
        if (! $staff) {
            return $this->sendError('The staff member no longer exists.', null, 404);
        }

        if (! $staff->trashed()) {
            $this->softDeleteStaff($staff);
        }

        $deletionRequest->update([
            'status' => StaffDeletionRequest::STATUS_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        try {
            if ((int) $deletionRequest->requested_by !== (int) $actor->id) {
                Notification::notify((int) $deletionRequest->requested_by, [
                    'category' => 'staff_deletion',
                    'type' => 'staff_deletion_approved',
                    'title' => 'Staff deletion approved',
                    'body' => sprintf('Your request to remove %s was approved.', $staff->name ?? 'the staff member'),
                    'icon' => 'check-square',
                    'deep_link' => '/staff',
                    'metadata' => ['staff_id' => $staff->id],
                ]);
            }
        } catch (\Throwable) {
        }

        return $this->sendResponse(['id' => $deletionRequest->id], 'Staff deletion approved');
    }

    /**
     * Reject a pending deletion request, keeping the staff member.
     */
    public function rejectDeletion(Request $request, $id)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());
        $actor = $request->user();

        if (!Schema::hasTable('staff_deletion_requests')) {
            return $this->sendError('Staff deletion requests are unavailable. Please run pending migrations.', null, 422);
        }

        if (! $this->userCanApproveStaffDeletion($actor)) {
            return $this->sendError('You do not have permission to reject staff deletions.', null, 403);
        }

        $deletionRequest = StaffDeletionRequest::whereIn('employer_id', $scope->business_ids)->findOrFail($id);

        if ($deletionRequest->status !== StaffDeletionRequest::STATUS_PENDING) {
            return $this->sendError('This deletion request has already been resolved.', null, 422);
        }

        $validated = $request->validate([
            'rejection_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $deletionRequest->update([
            'status' => StaffDeletionRequest::STATUS_REJECTED,
            'approved_by' => $actor->id,
            'rejected_at' => now(),
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ]);

        try {
            if ((int) $deletionRequest->requested_by !== (int) $actor->id) {
                Notification::notify((int) $deletionRequest->requested_by, [
                    'category' => 'staff_deletion',
                    'type' => 'staff_deletion_rejected',
                    'title' => 'Staff deletion rejected',
                    'body' => 'Your request to remove a staff member was rejected.',
                    'icon' => 'x-square',
                    'deep_link' => '/staff',
                    'metadata' => ['staff_id' => $deletionRequest->staff_id],
                ]);
            }
        } catch (\Throwable) {
        }

        return $this->sendResponse(['id' => $deletionRequest->id], 'Staff deletion rejected');
    }

    public function toggleStatus(Request $request, User $staff)
    {
        $scope = $this->resolveBusinessScope($request, $request->user());

        if (!in_array((int) $staff->parent_id, $scope->business_ids, true) || $staff->type !== User::TYPE_STAFF) {
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
        $scope = $this->resolveBusinessScope($request, $request->user());

        if (!in_array((int) $staff->parent_id, $scope->business_ids, true) || $staff->type !== User::TYPE_STAFF) {
            return $this->sendError('Unauthorized or staff not found.', null, 403);
        }

        $employer = User::find($staff->parent_id);
        
        // Create a password reset token
        $token = Password::createToken($staff);
        
        // Generate the invite link (you can customize this based on your frontend URL)
        $inviteLink = config('app.frontend_url', 'http://localhost:3000') . '/reset-password?token=' . $token . '&email=' . urlencode($staff->email) . '&role=staff';
        
        // Send the email
        Mail::to($staff->email)->send(new StaffInvitation($staff, $employer, $inviteLink));
        
        // Update invitation status
        $staff->update(['invitation_status' => 'Invited']);

        try {
            Notification::notify($staff, [
                'category' => 'team',
                'type' => 'team_invite',
                'title' => 'You were invited to join ' . ($employer?->company_name ?? $employer?->name ?? 'the team'),
                'body' => 'An invitation email has been sent to ' . $staff->email,
                'icon' => 'user-plus',
                'deep_link' => '/reset-password?token=' . $token . '&email=' . urlencode($staff->email) . '&role=staff',
                'metadata' => ['invited_by' => $request->user()->id, 'employer_id' => $staff->parent_id],
            ]);
            Notification::notify($request->user(), [
                'category' => 'team',
                'type' => 'team_invite_sent',
                'title' => 'Invitation sent',
                'body' => 'Invite sent to ' . ($staff->name ?? $staff->email),
                'icon' => 'mail',
                'deep_link' => '/team',
                'metadata' => ['staff_id' => $staff->id],
            ]);
        } catch (\Throwable) {
        }

        return $this->sendResponse(['invitation_status' => $staff->invitation_status], "Invitation sent to {$staff->email}");
    }

    public function bulkUpload(Request $request)
    {
        $singleBusinessId = $this->requireSingleBusinessScope($request, $request->user());
        $employer = User::find($singleBusinessId);

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
                'parent_id' => $singleBusinessId,
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

        // Handle Excel serial date numbers (e.g. 45292 -> 2024-01-01).
        // Excel stores dates as days since 1899-12-30; a reasonable range
        // for staff start dates / DOBs is roughly year 1900 to 2100.
        if (preg_match('/^\d+$/', $date)) {
            $serial = (int) $date;
            if ($serial > 1 && $serial < 80000) {
                try {
                    $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($serial);
                    if ($dateTime instanceof \DateTimeInterface) {
                        return Carbon::instance($dateTime)->format('Y-m-d');
                    }
                } catch (\Throwable) {
                    // fall through to other parsers
                }
            }
        }

        // Normalise internal whitespace so "15  Jan   2024" behaves like
        // "15 Jan 2024" for both the explicit list and Carbon fallback.
        $normalised = preg_replace('/\s+/', ' ', $date);

        // Try the explicit format list FIRST. It is precise, so it won't
        // silently produce a wrong-but-valid date the way a lenient parser
        // can (e.g. "15 Jan, 2024" must not become Jan 15 of the current
        // year). Covers slash/dash/dot separators, UK/US ordering, 2- and
        // 4-digit years, and named months (with and without commas).
        $supportedFormats = [
            // Day-first (UK / common in Nigeria)
            'd/m/Y', 'd-m-Y', 'd.m.Y',
            'd/m/y', 'd-m-y', 'd.m.y',
            'j/n/Y', 'j/n/y', 'j-n-Y', 'j.n.Y',
            // Month-first (US)
            'm/d/Y', 'm-d-Y', 'm.d.Y',
            'm/d/y', 'm-d-y',
            'n/j/Y', 'n/j/y',
            // Year-first
            'Y/m/d', 'Y-m-d', 'Y.m.d',
            'y/m/d', 'y-m-d',
            // Named months
            'd M Y', 'd F Y',
            'j M Y', 'j F Y',
            'M d Y', 'F d Y',
            'M j Y', 'F j Y',
            'F j, Y', 'M j, Y',
            'j M, Y', 'j F, Y',
            // With time component
            'Y-m-d\TH:i:s', 'Y-m-d H:i:s',
            'd/m/Y H:i:s', 'd-m-Y H:i:s',
        ];

        foreach ($supportedFormats as $format) {
            try {
                $parsedDate = Carbon::createFromFormat($format, $normalised);
                if ($parsedDate !== false) {
                    // Detect overflow: DateTime::createFromFormat silently wraps
                    // out-of-range components (e.g. month 15 -> March next year).
                    // getLastErrors() reports a warning in that case.
                    $lastErrors = \DateTime::getLastErrors();
                    if (is_array($lastErrors) && ($lastErrors['warning_count'] ?? 0) > 0) {
                        continue;
                    }
                    $year = (int) $parsedDate->format('Y');
                    $month = (int) $parsedDate->format('n');
                    $day = (int) $parsedDate->format('j');
                    if (!checkdate($month, $day, $year)) {
                        continue;
                    }
                    // Expand 2-digit years ("24" -> "2024").
                    if ($year < 100) {
                        $year += 2000;
                        $parsedDate = $parsedDate->setYear($year);
                    }
                    if ($year >= 1900 && $year <= 2100) {
                        return $parsedDate->format('Y-m-d');
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        // Final fallback: Carbon's lenient parser handles anything the
        // explicit list missed ("2024-01-15T00:00:00Z", "next Monday",
        // relative strings, etc.). Only accept results in a sane year
        // range and validate the calendar date to avoid overflow wraps.
        try {
            $parsed = Carbon::parse($normalised);
            $year = (int) $parsed->format('Y');
            $month = (int) $parsed->format('n');
            $day = (int) $parsed->format('j');
            if ($year >= 1900 && $year <= 2100 && checkdate($month, $day, $year)) {
                return $parsed->format('Y-m-d');
            }
        } catch (\Throwable) {
            // genuinely unparseable
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
        return config('app.frontend_url', 'https://sugarpayroll.com') . '/login';
    }

    private function normalizeBankName(string $bankName): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $bankName)));
    }

    public function verifyBank(Request $request)
    {
        $request->validate([
            'account_number' => 'required|string',
            'bank_code' => 'required|string',
        ]);

        $scope = $this->resolveBusinessScope($request, $request->user());

        $businessIds = array_map('intval', $scope->business_ids);

        if ($request->has('staff_id') && $request->staff_id !== null && $request->staff_id !== '') {
            $staffId = (int) $request->staff_id;
            $staffBelongs = User::where('id', $staffId)
                ->where('type', User::TYPE_STAFF)
                ->whereIn('parent_id', $businessIds)
                ->exists();
            if (!$staffBelongs) {
                return $this->sendError('Staff member not found in your authorized businesses.', null, 404);
            }
            $staff = User::find($staffId);
            $existing = (string) ($staff?->account_number ?? '');
            $incoming = trim((string) $request->account_number);
            if ($existing !== '' && $existing !== $incoming && ! $this->isBusinessOwner($request->user())) {
                // The account number is the payout destination. The owner may
                // change it (the new number is still re-verified with Sarepay
                // below); team members cannot.
                return $this->sendError(
                    "Only the business owner can change a staff member's account number. Please ask the account owner to update it.",
                    null,
                    403,
                );
            }
        }

        $result = $this->sarepayService->validateAccount(
            (string) $request->account_number,
            (string) $request->bank_code,
        );

        return $this->sendResponse($result->data, 'Bank account verified');
    }
}
