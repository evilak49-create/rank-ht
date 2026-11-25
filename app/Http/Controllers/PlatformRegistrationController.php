<?php

namespace App\Http\Controllers;

use App\Auth\MemberPhone;
use App\Platforms\PlatformRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;

class PlatformRegistrationController extends Controller
{
    public function launch(Request $request, int $platform, PlatformRegistration $registration): JsonResponse
    {
        return response()->json(['data' => ['url' => $registration->launch($request->user(), $platform)]]);
    }

    public function callback(Request $request, PlatformRegistration $registration): Response
    {
        $data = Validator::make($request->query(), [
            'bd_id' => ['required', 'string', MemberPhone::RULE],
            'platform' => ['required', 'string', 'max:120'],
        ]);
        if ($request->isMethod('GET') && $data->passes()) {
            $values = $data->validated();
            $registration->received($values['bd_id'], $values['platform']);
        }

        return response('');
    }
}
