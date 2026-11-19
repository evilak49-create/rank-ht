<?php

namespace App\Http\Controllers;

use App\Dashboard\VisitorActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class VisitorActivityController extends Controller
{
    public function __invoke(Request $request, VisitorActivity $activity): Response
    {
        $data = $request->validate(['visitor_id' => ['required', 'uuid:4']]);
        $activity->record($data['visitor_id']);

        return response()->noContent()->header('Cache-Control', 'no-store');
    }
}
