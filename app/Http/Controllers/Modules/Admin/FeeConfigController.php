<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\FeeConfig;
use App\Models\User;
use App\Traits\ResolvesFeeConfig;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeeConfigController extends Controller
{
    use ResolvesFeeConfig;

    public function index(Request $request)
    {
        $request->validate([
            'merchant_id' => ['nullable', 'integer', 'min:1'],
            'event' => ['nullable', 'string', Rule::in([
                FeeConfig::EVENT_INFLOW_TOPUP,
                FeeConfig::EVENT_OUTFLOW_DISBURSEMENT,
            ])],
        ]);

        $user = $request->user();
        $isSuperAdmin = $user->type === User::TYPE_SUPERADMIN;

        $query = FeeConfig::query()
            ->with(['setByUser:id,name,email', 'ownerUser:id,name,email,parent_id'])
            ->where('scope_type', FeeConfig::SCOPE_EMPLOYER);

        if ($isSuperAdmin && $request->filled('merchant_id')) {
            $merchantId = (int) $request->merchant_id;
            $employerIds = User::where('parent_id', $merchantId)
                ->where('type', User::TYPE_EMPLOYEE)
                ->pluck('id')
                ->toArray();
            $query->whereIn('scope_id', $employerIds);
        } elseif (!$isSuperAdmin) {
            $employerIds = $user->children()
                ->where('type', User::TYPE_EMPLOYEE)
                ->pluck('id')
                ->toArray();
            $query->whereIn('scope_id', $employerIds);
        }

        $query->when($request->filled('event'), function ($q) use ($request) {
            $q->where('event', $request->event);
        });

        $fees = $query->orderByDesc('id')->paginate(25);

        return $this->sendResponse($fees, 'Employer fee overrides retrieved successfully');
    }

    public function store(Request $request)
    {
        $request->validate([
            'scope_type' => ['required', 'string', Rule::in([FeeConfig::SCOPE_EMPLOYER])],
            'scope_id' => [
                'required',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($request) {
                    $employer = User::where('id', $value)->where('type', User::TYPE_EMPLOYEE)->first();
                    if (!$employer) {
                        $fail('The selected scope_id is not a valid employer user.');
                        return;
                    }
                    $user = $request->user();
                    if ($user->type !== User::TYPE_SUPERADMIN && $employer->parent_id !== $user->id) {
                        $fail('You are not authorized to set fee overrides for this employer.');
                    }
                },
            ],
            'event' => ['required', 'string', Rule::in([
                FeeConfig::EVENT_INFLOW_TOPUP,
                FeeConfig::EVENT_OUTFLOW_DISBURSEMENT,
            ])],
            'calculation_type' => ['required', 'string', Rule::in([
                FeeConfig::CALC_FLAT,
                FeeConfig::CALC_PERCENTAGE,
            ])],
            'value' => [
                'required',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->calculation_type === FeeConfig::CALC_PERCENTAGE && $value > 100) {
                        $fail('Percentage value must be between 0 and 100.');
                    }
                },
            ],
            'cap_amount' => [
                'nullable',
                'numeric',
                'min:0',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->calculation_type !== FeeConfig::CALC_PERCENTAGE && $value !== null) {
                        $fail('Cap amount is only allowed for percentage calculation type.');
                    }
                },
            ],
            'is_active' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $scopeType = FeeConfig::SCOPE_EMPLOYER;
        $scopeId = (int) $request->scope_id;

        if ($user->type !== User::TYPE_SUPERADMIN) {
            $employer = User::find($scopeId);
            if (!$employer || $employer->parent_id !== $user->id) {
                return $this->sendError('You are not authorized to set fee overrides for this employer.', null, 403);
            }
        }

        $fee = FeeConfig::updateOrCreate(
            [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'event' => $request->event,
            ],
            [
                'set_by_user_id' => $user->id,
                'calculation_type' => $request->calculation_type,
                'value' => $request->value,
                'cap_amount' => $request->calculation_type === FeeConfig::CALC_PERCENTAGE
                    ? $request->cap_amount
                    : null,
                'is_active' => $request->filled('is_active') ? (bool) $request->is_active : true,
                'metadata' => $request->input('metadata'),
            ]
        );

        $fee->load(['setByUser:id,name,email']);

        return $this->sendResponse($fee, 'Employer fee override saved successfully');
    }

    public function toggle(Request $request, string $id)
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $user = $request->user();
        $fee = FeeConfig::find($id);

        if (!$fee) {
            return $this->sendError('Fee configuration not found', null, 404);
        }

        if ($fee->scope_type !== FeeConfig::SCOPE_EMPLOYER) {
            return $this->sendError('You are not authorized to toggle this fee configuration.', null, 403);
        }

        if ($user->type !== User::TYPE_SUPERADMIN) {
            $employer = User::find($fee->scope_id);
            if (!$employer || $employer->parent_id !== $user->id) {
                return $this->sendError('You are not authorized to toggle this fee configuration.', null, 403);
            }
        }

        $fee->update([
            'is_active' => (bool) $request->is_active,
        ]);

        $fee->load(['setByUser:id,name,email']);

        return $this->sendResponse($fee, 'Fee configuration status updated');
    }

    public function previewAmount(Request $request)
    {
        $request->validate([
            'event' => ['required', 'string', Rule::in([
                FeeConfig::EVENT_INFLOW_TOPUP,
                FeeConfig::EVENT_OUTFLOW_DISBURSEMENT,
            ])],
            'amount' => ['required', 'numeric', 'min:0'],
            'employer_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        $event = $request->event;
        $amount = (float) $request->amount;
        $employerId = $request->input('employer_id');

        if ($employerId !== null && $user->type !== User::TYPE_SUPERADMIN) {
            $employer = User::find($employerId);
            if (!$employer || $employer->parent_id !== $user->id) {
                return $this->sendError('You are not authorized to preview fees for this employer.', null, 403);
            }
        }

        $resolved = [
            'fee' => null,
            'amount' => 0.0,
            'breakdown' => 'No matching FeeConfig row — fee defaults to 0.',
            'scope_label' => 'none',
            'calculation_label' => 'none',
        ];

        if ($employerId !== null) {
            $employer = User::find($employerId);
            if ($employer) {
                $resolved = $this->resolveFee($employer, $event);
                if ($resolved['fee']) {
                    $resolved['amount'] = $this->computeFee($amount, $resolved['fee']);
                }
            }
        } else {
            $platformDefault = FeeConfig::active()
                ->forEvent($event)
                ->forScope(FeeConfig::SCOPE_PLATFORM)
                ->first();

            if ($platformDefault) {
                $computed = $this->computeFee($amount, $platformDefault);
                $resolved = [
                    'fee' => $platformDefault,
                    'amount' => $computed,
                    'breakdown' => sprintf(
                        'Resolved via platform default scope_type=%s event=%s calculation_type=%s value=%s cap_amount=%s',
                        $platformDefault->scope_type,
                        $platformDefault->event,
                        $platformDefault->calculation_type,
                        $platformDefault->value,
                        $platformDefault->cap_amount ?? 'null'
                    ),
                    'scope_label' => $platformDefault->labelForScope(),
                    'calculation_label' => $platformDefault->labelForCalculation(),
                ];
            }
        }

        $netAmount = $event === FeeConfig::EVENT_INFLOW_TOPUP
            ? max(0, $amount - $resolved['amount'])
            : max(0, $amount + $resolved['amount']);

        $result = [
            'event' => $event,
            'gross_amount' => $amount,
            'fee_amount' => $resolved['amount'],
            'net_amount' => round($netAmount, 2),
            'breakdown' => $resolved['breakdown'],
            'scope_label' => $resolved['scope_label'],
            'calculation_label' => $resolved['calculation_label'],
        ];

        return $this->sendResponse($result, 'Fee preview computed successfully');
    }
}
