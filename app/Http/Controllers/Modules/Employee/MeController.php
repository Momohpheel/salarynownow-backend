<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\DeductionType;
use App\Models\Payroll;
use App\Models\User;
use App\Traits\ResolvesBusinessContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class MeController extends Controller
{
    use ResolvesBusinessContext;

    public function ownedBusinesses(Request $request)
    {
        $actingUser = $request->user();
        $ogOwner = $actingUser->resolveSharedWalletOwner();
        $businesses = $ogOwner->ownedBusinesses();

        if ($businesses->isEmpty()) {
            $businesses = collect([$actingUser->type === User::TYPE_EMPLOYEE ? $actingUser : $actingUser->employer()->first() ?? $actingUser]);
        }

        $ownerId = $ogOwner->id;
        $businessIds = $businesses->pluck('id')->all();

        $staffCounts = User::whereIn('parent_id', $businessIds)
            ->where('type', User::TYPE_STAFF)
            ->select('parent_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('parent_id')
            ->get()
            ->keyBy('parent_id');

        $latestPayrollSubquery = Payroll::select(DB::raw('MAX(id) as max_id, user_id'))
            ->whereIn('user_id', $businessIds)
            ->groupBy('user_id');

        $latestPayrolls = Payroll::joinSub($latestPayrollSubquery, 'lp', function ($join) {
            $join->on('payrolls.id', '=', 'lp.max_id');
        })
            ->select('payrolls.user_id', 'payrolls.id as payroll_id', 'payrolls.status')
            ->get()
            ->keyBy('user_id');

        $result = $businesses->map(function ($biz) use ($ownerId, $staffCounts, $latestPayrolls) {
            $bizId = $biz->id;
            $latest = $latestPayrolls->get($bizId);
            return [
                'id' => $biz->id,
                'company_name' => $biz->company_name ?? $biz->name,
                'email' => $biz->email,
                'staff_count' => (int) ($staffCounts->get($bizId)?->cnt ?? 0),
                'latest_payroll_status' => $latest?->status ?? null,
                'latest_payroll_id' => $latest?->payroll_id ?? null,
                'is_owner' => (int) $bizId === (int) $ownerId,
            ];
        });

        $ownerRow = $result->firstWhere('is_owner', true);
        $otherRows = $result->where('is_owner', false)->sortBy('company_name', SORT_NATURAL | SORT_FLAG_CASE)->values();

        $sorted = collect();
        if ($ownerRow) {
            $sorted->push($ownerRow);
        }
        foreach ($otherRows as $row) {
            $sorted->push($row);
        }

        return $this->sendResponse($sorted->values()->all(), 'Owned businesses retrieved successfully');
    }

    public function index(Request $request)
    {
        return $this->ownedBusinesses($request);
    }

    public function store(Request $request)
    {
        $actingUser = $request->user();
        $ogOwner = $actingUser->resolveSharedWalletOwner();

        if ((int) $actingUser->id !== (int) $ogOwner->id) {
            return $this->sendError('Only the account owner can create sub-businesses', null, 403);
        }

        $ownerGroupIds = $ogOwner->ownedBusinesses()->pluck('id')->all();

        $validator = Validator::make($request->all(), [
            'company_name' => [
                'required',
                'string',
                'max:191',
                function ($attribute, $value, $fail) use ($ownerGroupIds) {
                    $exists = User::whereIn('id', $ownerGroupIds)
                        ->where(function ($q) use ($value) {
                            $q->where('company_name', $value)
                                ->orWhere('name', $value);
                        })
                        ->exists();
                    if ($exists) {
                        $fail('The company name has already been taken within your business group.');
                    }
                },
            ],
            'email' => 'required|string|email|max:191|unique:users,email',
            'phone_number' => 'nullable|string|max:30',
            'rc_number' => 'nullable|string|max:64',
            'industry' => 'nullable|string|max:128',
            'company_address' => 'nullable|string',
            'number_of_staff' => 'nullable|integer',
            'clone_deduction_types' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed', $validator->errors(), 422);
        }

        $validated = $validator->validated();
        $cloneDeductionTypes = $validated['clone_deduction_types'] ?? true;

        DB::beginTransaction();
        try {
            $newBiz = new User();
            $newBiz->type = User::TYPE_EMPLOYEE;
            $newBiz->parent_id = $ogOwner->parent_id;
            $newBiz->owner_group_user_id = $ogOwner->id;
            $newBiz->password = bcrypt(Str::random(32));

            $newBiz->company_name = $validated['company_name'];
            $newBiz->name = $validated['company_name'];
            $newBiz->email = $validated['email'];
            $newBiz->phone_number = $validated['phone_number'] ?? null;
            $newBiz->company_address = $validated['company_address'] ?? null;
            $newBiz->number_of_staff = $validated['number_of_staff'] ?? null;

            $newBiz->is_approved = $ogOwner->is_approved;

            if (Schema::hasColumn('users', 'status')) {
                $newBiz->status = $ogOwner->status ?? null;
            }
            if (Schema::hasColumn('users', 'suspension_reason')) {
                $newBiz->suspension_reason = $ogOwner->suspension_reason ?? null;
            }

            $newBiz->cac_certificate_path = $ogOwner->cac_certificate_path ?? null;
            $newBiz->director_id_path = $ogOwner->director_id_path ?? null;
            $newBiz->utility_bill_path = $ogOwner->utility_bill_path ?? null;
            $newBiz->plan_tier = $ogOwner->plan_tier ?? null;
            $newBiz->state = $ogOwner->state ?? null;
            $newBiz->pension_employee_rate = $ogOwner->pension_employee_rate ?? null;
            $newBiz->pension_employer_rate = $ogOwner->pension_employer_rate ?? null;
            $newBiz->tax_deduction = $ogOwner->tax_deduction ?? null;
            $newBiz->nhf = $ogOwner->nhf ?? null;

            if (isset($validated['rc_number']) && $validated['rc_number'] !== null && $validated['rc_number'] !== '') {
                $newBiz->rc_number = $validated['rc_number'];
            } else {
                $newBiz->rc_number = $ogOwner->rc_number ?? null;
            }

            if (isset($validated['industry']) && $validated['industry'] !== null && $validated['industry'] !== '') {
                $newBiz->industry = $validated['industry'];
            } else {
                $newBiz->industry = $ogOwner->industry ?? null;
            }

            $newBiz->save();

            if ($cloneDeductionTypes) {
                $ownerDeductions = DeductionType::where('user_id', $ogOwner->id)
                    ->where('is_system', false)
                    ->get();

                foreach ($ownerDeductions as $ded) {
                    DeductionType::create([
                        'user_id' => $newBiz->id,
                        'name' => $ded->name,
                        'description' => $ded->description,
                        'default_amount' => $ded->default_amount,
                        'is_percentage' => $ded->is_percentage,
                        'percentage_value' => $ded->percentage_value,
                        'is_active' => $ded->is_active,
                        'is_system' => false,
                        'system_key' => null,
                    ]);
                }
            }

            $staffCount = (int) User::where('parent_id', $newBiz->id)
                ->where('type', User::TYPE_STAFF)
                ->count();

            $latestPayroll = Payroll::where('user_id', $newBiz->id)
                ->orderByDesc('id')
                ->first();

            DB::commit();

            $response = [
                'id' => $newBiz->id,
                'company_name' => $newBiz->company_name ?? $newBiz->name,
                'email' => $newBiz->email,
                'phone_number' => $newBiz->phone_number,
                'rc_number' => $newBiz->rc_number,
                'industry' => $newBiz->industry,
                'company_address' => $newBiz->company_address,
                'number_of_staff' => $newBiz->number_of_staff,
                'staff_count' => $staffCount,
                'latest_payroll_status' => $latestPayroll?->status ?? null,
                'latest_payroll_id' => $latestPayroll?->id ?? null,
                'is_owner' => false,
                'created_at' => $newBiz->created_at,
            ];

            return $this->sendResponse($response, 'Sub-business created successfully', true, 201);
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError('Failed to create sub-business: ' . $e->getMessage(), null, 500);
        }
    }

    public function show(Request $request, $business)
    {
        $actingUser = $request->user();
        $ogOwner = $actingUser->resolveSharedWalletOwner();
        $ownerId = (int) $ogOwner->id;

        $biz = User::where('id', (int) $business)
            ->where('type', User::TYPE_EMPLOYEE)
            ->where(function ($q) use ($ownerId) {
                $q->where('owner_group_user_id', $ownerId)
                    ->orWhere('id', $ownerId);
            })
            ->first();

        if (!$biz) {
            return $this->sendError('Business not found or not in your authorized group', null, 404);
        }

        $bizId = $biz->id;

        $staffCount = (int) User::where('parent_id', $bizId)
            ->where('type', User::TYPE_STAFF)
            ->count();

        $latestPayroll = Payroll::where('user_id', $bizId)
            ->orderByDesc('id')
            ->first();

        $result = [
            'id' => $biz->id,
            'company_name' => $biz->company_name ?? $biz->name,
            'email' => $biz->email,
            'phone_number' => $biz->phone_number,
            'rc_number' => $biz->rc_number,
            'industry' => $biz->industry,
            'company_address' => $biz->company_address,
            'number_of_staff' => $biz->number_of_staff,
            'staff_count' => $staffCount,
            'latest_payroll_status' => $latestPayroll?->status ?? null,
            'latest_payroll_id' => $latestPayroll?->id ?? null,
            'is_owner' => (int) $bizId === $ownerId,
            'created_at' => $biz->created_at,
        ];

        return $this->sendResponse($result, 'Business retrieved successfully');
    }

    public function update(Request $request, $business)
    {
        $actingUser = $request->user();
        $ogOwner = $actingUser->resolveSharedWalletOwner();

        if ((int) $actingUser->id !== (int) $ogOwner->id) {
            return $this->sendError('Only the account owner can edit sub-businesses', null, 403);
        }

        $ownerId = (int) $ogOwner->id;

        $biz = User::where('id', (int) $business)
            ->where('type', User::TYPE_EMPLOYEE)
            ->where('owner_group_user_id', $ownerId)
            ->where('id', '!=', $ownerId)
            ->first();

        if (!$biz) {
            return $this->sendError('Sub-business not found in your authorized group', null, 404);
        }

        $ownerGroupIds = $ogOwner->ownedBusinesses()->pluck('id')->all();
        $bizId = $biz->id;

        $validator = Validator::make($request->all(), [
            'company_name' => [
                'nullable',
                'string',
                'max:191',
                function ($attribute, $value, $fail) use ($ownerGroupIds, $bizId) {
                    $exists = User::whereIn('id', $ownerGroupIds)
                        ->where('id', '!=', $bizId)
                        ->where(function ($q) use ($value) {
                            $q->where('company_name', $value)
                                ->orWhere('name', $value);
                        })
                        ->exists();
                    if ($exists) {
                        $fail('The company name has already been taken within your business group.');
                    }
                },
            ],
            'email' => [
                'nullable',
                'string',
                'email',
                'max:191',
                "unique:users,email,{$bizId}",
            ],
            'phone_number' => 'nullable|string|max:30',
            'rc_number' => 'nullable|string|max:64',
            'industry' => 'nullable|string|max:128',
            'company_address' => 'nullable|string',
            'number_of_staff' => 'nullable|integer',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Validation failed', $validator->errors(), 422);
        }

        $validated = $validator->validated();

        if (isset($validated['company_name'])) {
            $biz->company_name = $validated['company_name'];
            $biz->name = $validated['company_name'];
        }
        if (isset($validated['email'])) {
            $biz->email = $validated['email'];
        }
        if (array_key_exists('phone_number', $validated)) {
            $biz->phone_number = $validated['phone_number'];
        }
        if (array_key_exists('rc_number', $validated)) {
            $biz->rc_number = $validated['rc_number'];
        }
        if (array_key_exists('industry', $validated)) {
            $biz->industry = $validated['industry'];
        }
        if (array_key_exists('company_address', $validated)) {
            $biz->company_address = $validated['company_address'];
        }
        if (array_key_exists('number_of_staff', $validated)) {
            $biz->number_of_staff = $validated['number_of_staff'];
        }

        $biz->save();

        $staffCount = (int) User::where('parent_id', $bizId)
            ->where('type', User::TYPE_STAFF)
            ->count();

        $latestPayroll = Payroll::where('user_id', $bizId)
            ->orderByDesc('id')
            ->first();

        $result = [
            'id' => $biz->id,
            'company_name' => $biz->company_name ?? $biz->name,
            'email' => $biz->email,
            'phone_number' => $biz->phone_number,
            'rc_number' => $biz->rc_number,
            'industry' => $biz->industry,
            'company_address' => $biz->company_address,
            'number_of_staff' => $biz->number_of_staff,
            'staff_count' => $staffCount,
            'latest_payroll_status' => $latestPayroll?->status ?? null,
            'latest_payroll_id' => $latestPayroll?->id ?? null,
            'is_owner' => false,
            'created_at' => $biz->created_at,
        ];

        return $this->sendResponse($result, 'Sub-business updated successfully');
    }

    public function destroy(Request $request, $business)
    {
        $actingUser = $request->user();
        $ogOwner = $actingUser->resolveSharedWalletOwner();

        if ((int) $actingUser->id !== (int) $ogOwner->id) {
            return $this->sendError('Only the account owner can unlink sub-businesses', null, 403);
        }

        $ownerId = (int) $ogOwner->id;
        $bizId = (int) $business;

        if ($bizId === $ownerId) {
            return $this->sendError('Cannot unlink the primary owner business from your account', null, 400);
        }

        $biz = User::where('id', $bizId)
            ->where('type', User::TYPE_EMPLOYEE)
            ->where('owner_group_user_id', $ownerId)
            ->first();

        if (!$biz) {
            return $this->sendError('Sub-business not found in your authorized group', null, 404);
        }

        $activePayrolls = Payroll::where('user_id', $bizId)
            ->whereNotIn('status', [Payroll::STATUS_COMPLETED, Payroll::STATUS_FAILED])
            ->exists();

        if ($activePayrolls) {
            return $this->sendError('Cannot unlink this business because it has active or pending payrolls. Please complete or cancel all in-progress payrolls first.', null, 409);
        }

        $biz->owner_group_user_id = null;
        $biz->save();

        return $this->sendResponse([
            'id' => $bizId,
            'unlinked' => true,
        ], 'Sub-business unlinked from your businesses successfully');
    }
}
