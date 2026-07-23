<?php

namespace App\Http\Controllers\Modules\Employer;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployerProfileController extends Controller
{
    public function update(Request $request)
    {
        $employer = $request->user();

        if ($employer->type !== User::TYPE_EMPLOYEE) {
            return $this->sendError('Unauthorized. Only employers can update their profile.', null, 403);
        }

        $validatedData = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:255'],
            'rc_number' => ['sometimes', 'string', 'max:255'],
            'industry' => ['sometimes', 'string', 'max:255'],
            'company_address' => ['sometimes', 'string', 'max:255'],
            'number_of_staff' => ['sometimes', 'integer', 'min:0'],
            'bvn' => ['sometimes', 'string', 'max:11'],
            'contact_person' => ['sometimes', 'string', 'max:255'],
            'phone_number' => ['sometimes', 'string', 'max:20'],
            'state' => ['sometimes', 'string', 'max:255'],
        ]);

        $employer->update($validatedData);

        return $this->sendResponse($employer, 'Employer profile updated successfully.');
    }
}
