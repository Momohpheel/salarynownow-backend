<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Support\Str;

class MerchantController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('type', User::TYPE_ADMIN)
            ->withCount(['children as employers_count' => function($query) {
                $query->where('type', User::TYPE_EMPLOYEE);
            }]);

        if ($request->search) {
            $query->where(function($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            });
        }

        if ($request->status && $request->status !== 'All') {
            $query->where('status', strtolower($request->status));
        }

        $merchants = $query->get()->map(function($merchant) {
            $staffReach = User::whereIn('parent_id', function($query) use ($merchant) {
                $query->select('id')->from('users')->where('parent_id', $merchant->id);
            })->where('type', User::TYPE_STAFF)->count();

            return [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'contact_person' => $merchant->contact_person,
                'email' => $merchant->email,
                'phone' => $merchant->phone_number,
                'state' => $merchant->state,
                'employers' => $merchant->employers_count,
                'staff' => $staffReach,
                'rev_share' => $merchant->revenue_share . '%',
                'status' => $merchant->status,
                'monthly_revenue' => '₦0', // Placeholder
            ];
        });

        return $this->sendResponse($merchants, 'Merchants retrieved successfully');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'link_name' => ['nullable', 'string', 'max:255', 'unique:users,link_name'],
            'contact_person' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'phone_number' => ['required', 'string', 'max:20'],
            'state' => ['required', 'string'],
            'revenue_share' => ['required', 'numeric', 'min:0', 'max:100'],
            'plan_tier' => ['required', 'string'],
            'internal_notes' => ['nullable', 'string'],
            //'password' => ['required', Rules\Password::defaults()],
        ]);

         $password = '123456';
        $merchant = User::create([
            'name' => $request->name,
            'contact_person' => $request->contact_person,
            'email' => $request->email,
            'phone_number' => $request->phone_number,
            'state' => $request->state,
            'revenue_share' => $request->revenue_share,
            'plan_tier' => $request->plan_tier,
            'internal_notes' => $request->internal_notes,
            'link_name' => $request->link_name ?? Str::slug($request->name),
            'password' => Hash::make($password),
            'type' => User::TYPE_ADMIN,
            'status' => 'active',
            'is_approved' => true,
            'is_active' => true,
        ]);

        return $this->sendResponse($merchant, 'Merchant invited successfully', true, 201);
    }

    public function show(User $merchant)
    {
        if ($merchant->type !== User::TYPE_ADMIN) {
            return $this->sendError('User is not a merchant', null, 404);
        }

        return $this->sendResponse($merchant, 'Merchant details retrieved');
    }
}
