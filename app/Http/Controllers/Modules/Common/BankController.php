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
            
            return $this->sendResponse($banks, 'Banks retrieved successfully');
        } catch (\Exception $e) {
            return $this->sendError('Failed to fetch banks.', $e->getMessage(), 500);
        }
    }
}
