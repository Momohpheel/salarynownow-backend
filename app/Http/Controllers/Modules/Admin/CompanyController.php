<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $companies = User::where('type', User::TYPE_EMPLOYEE)->get();

        return $this->sendResponse($companies, 'Companies retrieved successfully');
    }

    public function show(User $company)
    {
        $company->load(['staff', 'payrolls.transactions', 'wallet']);

        $data = [
            'company_info' => $company,
        ];

        return $this->sendResponse($data, 'Company details retrieved successfully');
    }

    public function deactivate(User $company)
    {
        $company->update(['is_active' => false]);

        return $this->sendResponse($company, 'Company deactivated successfully');
    }
}
