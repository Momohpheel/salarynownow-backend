<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Unified response format.
     */
    protected function sendResponse($data = null, string $message = 'Request successful', bool $status = true, int $code = 200)
    {
        return response()->json([
            'status' => $status,
            'message' => $message,
            'data' => $data,
        ], $code);
    }

    /**
     * Unified error response format.
     */
    protected function sendError(string $message = 'Request failed', $data = null, int $code = 400)
    {
        return $this->sendResponse($data, $message, false, $code);
    }
}
