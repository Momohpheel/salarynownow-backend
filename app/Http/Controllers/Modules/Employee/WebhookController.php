<?php

namespace App\Http\Controllers\Modules\Employee;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
     public function sarepayWebhook(Request $request)
    {
       
        if ($request->event == "collection.virtualaccount.successful") {
            return $this->virtualAccountWebHook($request);
        }
    
       
        return $this->sendResponse([], 'Sarepay webhook called');
    }

    public function virtualAccountWebHook()
    {

    }
  

}