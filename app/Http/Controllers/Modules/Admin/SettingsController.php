<?php

namespace App\Http\Controllers\Modules\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key');
        return $this->sendResponse($settings, 'Settings retrieved successfully');
    }

    public function store(Request $request)
    {
        $request->validate([
            'settings' => ['required', 'array'],
        ]);

        foreach ($request->settings as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'admin_id' => $request->user()->id,
                ]
            );
        }

        return $this->sendResponse(Setting::all()->pluck('value', 'key'), 'Settings saved successfully');
    }
}
