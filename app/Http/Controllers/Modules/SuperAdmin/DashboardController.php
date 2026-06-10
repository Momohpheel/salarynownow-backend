<?php

namespace App\Http\Controllers\Modules\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Payroll;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        // 1. Stat Cards Data
        $merchantsCount = User::where('type', User::TYPE_ADMIN)->count();
        $activeMerchants = User::where('type', User::TYPE_ADMIN)->where('status', 'active')->count();
        $suspendedMerchants = User::where('type', User::TYPE_ADMIN)->where('status', 'suspended')->count();
        
        $employersCount = User::where('type', User::TYPE_EMPLOYEE)->count();
        $staffCount = User::where('type', User::TYPE_STAFF)->count();
        
        $totalGrossPayroll = Payroll::sum('amount');
        
        // 2. Merchant Hierarchy (List of merchants with counts)
        $merchants = User::where('type', User::TYPE_ADMIN)
            ->withCount(['children as employers_count' => function($query) {
                $query->where('type', User::TYPE_EMPLOYEE);
            }])
            ->get()
            ->map(function($merchant) {
                // Calculate staff reach for this merchant
                $staffReach = User::whereIn('parent_id', function($query) use ($merchant) {
                    $query->select('id')->from('users')->where('parent_id', $merchant->id);
                })->where('type', User::TYPE_STAFF)->count();

                return [
                    'id' => $merchant->id,
                    'name' => $merchant->name,
                    'contact_person' => $merchant->contact_person ?? 'Unknown',
                    'employers' => $merchant->employers_count,
                    'staff' => $staffReach,
                    'status' => $merchant->status,
                    'link_name' => $merchant->link_name,
                ];
            });

        $data = [
            'stats' => [
                'merchants' => $merchantsCount,
                    
                'active_merchants' => $activeMerchants,
                    
                'employers' =>  $employersCount,
                    
                'staff_reach' => $staffCount,
                    
                'platform_revenue' => '₦' . $this->formatLargeAmount($totalGrossPayroll * 0.05), // Placeholder 5% take
                    
            ],
            'merchant' => $merchants,
            'approvals' =>  $merchants,
            'revenue_waterfall' => [
                'gross_payroll' => '₦' . number_format($totalGrossPayroll, 0),
                'merchant_share' => '₦' . number_format($totalGrossPayroll * 0.02, 0),
                'snn_take' => '₦' . number_format($totalGrossPayroll * 0.03, 0),
                'ops_cost' => '₦' . number_format($totalGrossPayroll * 0.005, 0),
                'net_revenue' => '₦' . number_format($totalGrossPayroll * 0.025, 0),
            ]
        ];

        return $this->sendResponse($data, 'SuperAdmin dashboard data retrieved successfully');
    }

    private function formatLargeAmount($amount)
    {
        if ($amount >= 1000000) {
            return number_format($amount / 1000000, 1) . 'M';
        }
        if ($amount >= 1000) {
            return number_format($amount / 1000, 1) . 'K';
        }
        return number_format($amount, 0);
    }
}
