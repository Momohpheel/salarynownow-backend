<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

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
            'scope_type' => ['nullable', 'string', Rule::in([
                FeeConfig::SCOPE_PLATFORM,
                FeeConfig::SCOPE_MERCHANT,
                FeeConfig::SCOPE_PARTNER,
                FeeConfig::SCOPE_EMPLOYER,
            ])],
            'event' => ['nullable', 'string', Rule::in([
                FeeConfig::EVENT_INFLOW_TOPUP,
                FeeConfig::EVENT_OUTFLOW_DISBURSEMENT,
            ])],
            'scope_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = FeeConfig::query()
            ->with(['setByUser:id,name,email'])
            ->when($request->filled('scope_type'), function ($q) use ($request) {
                $q->where('scope_type', $request->scope_type);
            })
            ->when($request->filled('event'), function ($q) use ($request) {
                $q->where('event', $request->event);
            })
            ->when($request->filled('scope_id'), function ($q) use ($request) {
                $q->where('scope_id', $request->scope_id);
            })
            ->orderByDesc('id');

        $fees = $query->paginate(25);

        return $this->sendResponse($fees, 'Fee configurations retrieved successfully');
    }

    public function store(Request $request)
    {
        $request->validate([
            'scope_type' => ['required', 'string', Rule::in([
                FeeConfig::SCOPE_PLATFORM,
                FeeConfig::SCOPE_MERCHANT,
                FeeConfig::SCOPE_PARTNER,
                FeeConfig::SCOPE_EMPLOYER,
            ])],
            'scope_id' => [
                Rule::requiredIf(function () use ($request) {
                    return $request->scope_type !== FeeConfig::SCOPE_PLATFORM;
                }),
                'nullable',
                'integer',
                'min:1',
                function ($attribute, $value, $fail) use ($request) {
                    if ($request->scope_type === FeeConfig::SCOPE_PLATFORM) {
                        return;
                    }
                    if ($value === null) {
                        return;
                    }
                    $typeMap = [
                        FeeConfig::SCOPE_MERCHANT => User::TYPE_ADMIN,
                        FeeConfig::SCOPE_PARTNER => User::TYPE_PARTNER,
                        FeeConfig::SCOPE_EMPLOYER => User::TYPE_EMPLOYEE,
                    ];
                    $expectedType = $typeMap[$request->scope_type] ?? null;
                    if ($expectedType === null) {
                        return;
                    }
                    $exists = User::where('id', $value)->where('type', $expectedType)->exists();
                    if (!$exists) {
                        $fail(sprintf('The selected scope_id is not a valid %s user.', $expectedType));
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

        $scopeType = $request->scope_type;
        $scopeId = $scopeType === FeeConfig::SCOPE_PLATFORM ? null : $request->scope_id;

        $fee = FeeConfig::updateOrCreate(
            [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'event' => $request->event,
            ],
            [
                'set_by_user_id' => $request->user()->id,
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

        return $this->sendResponse($fee, 'Fee configuration saved successfully');
    }

    public function toggle(Request $request, string $id)
    {
        $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $fee = FeeConfig::find($id);

        if (!$fee) {
            return $this->sendError('Fee configuration not found', null, 404);
        }

        $fee->update([
            'is_active' => (bool) $request->is_active,
        ]);

        $fee->load(['setByUser:id,name,email']);

        return $this->sendResponse($fee, 'Fee configuration status updated');
    }

    public function destroy(string $id)
    {
        $fee = FeeConfig::find($id);

        if (!$fee) {
            return $this->sendError('Fee configuration not found', null, 404);
        }

        $fee->delete();

        return $this->sendResponse(null, 'Fee configuration deleted successfully');
    }

    public function previewAmount(Request $request)
    {
        $request->validate([
            'event' => ['required', 'string', Rule::in([
                FeeConfig::EVENT_INFLOW_TOPUP,
                FeeConfig::EVENT_OUTFLOW_DISBURSEMENT,
            ])],
            'amount' => ['required', 'numeric', 'min:0'],
            'scope_type' => ['nullable', 'string', Rule::in([
                FeeConfig::SCOPE_PLATFORM,
                FeeConfig::SCOPE_MERCHANT,
                FeeConfig::SCOPE_PARTNER,
                FeeConfig::SCOPE_EMPLOYER,
            ])],
            'scope_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $event = $request->event;
        $amount = (float) $request->amount;
        $scopeType = $request->input('scope_type');
        $scopeId = $request->input('scope_id');

        $resolved = [
            'fee' => null,
            'amount' => 0.0,
            'breakdown' => 'No matching FeeConfig row — fee defaults to 0.',
            'scope_label' => 'none',
            'calculation_label' => 'none',
        ];

        if ($scopeType && $scopeId) {
            $candidate = FeeConfig::active()
                ->forEvent($event)
                ->forScope($scopeType, $scopeId)
                ->first();

            if ($candidate) {
                $computed = $this->computeFee($amount, $candidate);
                $resolved = [
                    'fee' => $candidate,
                    'amount' => $computed,
                    'breakdown' => sprintf(
                        'Resolved via scope_type=%s scope_id=%s event=%s calculation_type=%s value=%s cap_amount=%s',
                        $candidate->scope_type,
                        $candidate->scope_id ?? 'null',
                        $candidate->event,
                        $candidate->calculation_type,
                        $candidate->value,
                        $candidate->cap_amount ?? 'null'
                    ),
                    'scope_label' => $candidate->labelForScope(),
                    'calculation_label' => $candidate->labelForCalculation(),
                ];
            }
        } elseif ($scopeType === FeeConfig::SCOPE_PLATFORM) {
            $candidate = FeeConfig::active()
                ->forEvent($event)
                ->forScope(FeeConfig::SCOPE_PLATFORM)
                ->first();

            if ($candidate) {
                $computed = $this->computeFee($amount, $candidate);
                $resolved = [
                    'fee' => $candidate,
                    'amount' => $computed,
                    'breakdown' => sprintf(
                        'Resolved via scope_type=%s event=%s calculation_type=%s value=%s cap_amount=%s',
                        $candidate->scope_type,
                        $candidate->event,
                        $candidate->calculation_type,
                        $candidate->value,
                        $candidate->cap_amount ?? 'null'
                    ),
                    'scope_label' => $candidate->labelForScope(),
                    'calculation_label' => $candidate->labelForCalculation(),
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
