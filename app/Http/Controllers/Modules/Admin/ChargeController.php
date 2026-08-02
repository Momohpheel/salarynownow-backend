<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ChargeController extends Controller
{
    public function index()
    {
        $charges = Charge::all();
        return $this->sendResponse($charges, 'Charges retrieved successfully');
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', Rule::in(['wallet-top-up', 'disbursement'])],
            'type' => ['required', 'string', 'in:percentage,fixed'],
            'amount' => ['required', 'numeric', 'min:0'],
            'cap' => ['nullable', 'numeric', 'min:0'],
        ]);

        $charge = Charge::updateOrCreate(
            ['name' => $request->name],
            [
                'type' => $request->type,
                'amount' => $request->amount,
                'cap' => $request->cap,
                'admin_id' => $request->user()->id,
            ]
        );

        return $this->sendResponse($charge, 'Charge saved successfully');
    }
}
