<?php

namespace App\Http\Controllers\Modules\Common;

use App\Http\Controllers\Controller;
use App\Services\Sarepay\SarepayService;
use Illuminate\Http\Request;

class BankController extends Controller
{
    protected $sarepayService;

    public function __construct(SarepayService $sarepayService)
    {
        $this->sarepayService = $sarepayService;
    }

    /**
     * Get all banks from Sarepay.
     */
    public function index()
    {
        try {
            $banks = $this->sarepayService->getBanks();
            
            return response()->json([
                'status' => 'success',
                'data' => $banks,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to fetch banks.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
