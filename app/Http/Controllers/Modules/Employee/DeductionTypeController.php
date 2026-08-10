<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use App\Models\DeductionType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DeductionTypeController extends Controller
{
    public function index(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $query = DeductionType::forEmployer($employerId)->orderBy('is_system', 'desc')->orderBy('name');

        if ($request->filled('active_only')) {
            $query->active();
        }

        $items = $query->get()->map(function ($d) {
            return [
                'id' => $d->id,
                'name' => $d->name,
                'description' => $d->description,
                'default_amount' => (float) $d->default_amount,
                'is_percentage' => (bool) $d->is_percentage,
                'percentage_value' => $d->percentage_value ? (float) $d->percentage_value : null,
                'is_active' => (bool) $d->is_active,
                'is_system' => (bool) $d->is_system,
                'system_key' => $d->system_key,
                'created_at' => $d->created_at?->toISOString(),
            ];
        })->values();

        return $this->sendResponse($items, 'Deduction types retrieved successfully');
    }

    public function store(Request $request)
    {
        $employerId = $request->user()->getEmployerId();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('deduction_types', 'name')->where(fn ($q) => $q->where('user_id', $employerId)->whereNull('deleted_at'))],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_amount' => ['nullable', 'numeric', 'min:0'],
            'is_percentage' => ['nullable', 'boolean'],
            'percentage_value' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!empty($validated['is_percentage']) && empty($validated['percentage_value'])) {
            return $this->sendError('A percentage value is required for percentage-based deductions.', null, 422);
        }

        $deduction = DeductionType::create(array_merge($validated, [
            'user_id' => $employerId,
            'default_amount' => $validated['default_amount'] ?? 0,
            'is_percentage' => $validated['is_percentage'] ?? false,
            'is_active' => $validated['is_active'] ?? true,
            'is_system' => false,
        ]));

        return $this->sendResponse($deduction, 'Deduction type created successfully', true, 201);
    }

    public function update(Request $request, DeductionType $deductionType)
    {
        $employerId = $request->user()->getEmployerId();

        if ($deductionType->is_system || $deductionType->user_id !== $employerId) {
            return $this->sendError('Only custom deduction types can be edited.', null, 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255', Rule::unique('deduction_types', 'name')->where(fn ($q) => $q->where('user_id', $employerId)->whereNull('deleted_at'))->ignore($deductionType->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'default_amount' => ['nullable', 'numeric', 'min:0'],
            'is_percentage' => ['nullable', 'boolean'],
            'percentage_value' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if (!empty($validated['is_percentage']) && !empty($validated['percentage_value']) === false && isset($validated['is_percentage'])) {
            if ($validated['is_percentage'] && empty($validated['percentage_value']) && empty($deductionType->percentage_value)) {
                return $this->sendError('A percentage value is required for percentage-based deductions.', null, 422);
            }
        }

        $deductionType->update($validated);

        return $this->sendResponse($deductionType, 'Deduction type updated successfully');
    }

    public function destroy(Request $request, DeductionType $deductionType)
    {
        $employerId = $request->user()->getEmployerId();

        if ($deductionType->is_system || $deductionType->user_id !== $employerId) {
            return $this->sendError('Only custom deduction types can be deleted.', null, 403);
        }

        $deductionType->delete();

        return $this->sendResponse(null, 'Deduction type deleted successfully');
    }

    public function toggle(Request $request, DeductionType $deductionType)
    {
        $employerId = $request->user()->getEmployerId();

        if ($deductionType->user_id !== $employerId && !$deductionType->is_system) {
            return $this->sendError('Deduction type not found.', null, 404);
        }

        if ($deductionType->user_id !== $employerId && $deductionType->is_system) {
            // For system types, create a employer-level override by cloning an inactive copy per employer? Simpler: treat toggle as informational 403
            return $this->sendError('System deduction types cannot be toggled. De-select them when configuring payroll to skip.', null, 403);
        }

        $deductionType->update(['is_active' => !$deductionType->is_active]);

        return $this->sendResponse($deductionType, 'Deduction type toggled successfully');
    }
}
