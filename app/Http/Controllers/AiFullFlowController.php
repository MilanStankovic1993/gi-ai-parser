<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Ai\AiFlowController;
use Illuminate\Http\Request;

class AiFullFlowController extends Controller
{
    public function handle(Request $request, AiFlowController $flow)
    {
        return $flow->handle($request);
    }
}
