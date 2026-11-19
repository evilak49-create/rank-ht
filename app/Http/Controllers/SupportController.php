<?php

namespace App\Http\Controllers;

use App\SupportDesk\CustomerSupport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function index(Request $request, CustomerSupport $support): JsonResponse
    {
        $data = $request->validate(['language' => ['nullable', 'in:pt-BR,zh-CN']]);

        return response()->json(['data' => $support->publicSettings($data['language'] ?? 'pt-BR')])
            ->header('Cache-Control', 'no-store');
    }

    public function chat(Request $request, CustomerSupport $support): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'language' => ['nullable', 'in:pt-BR,zh-CN'],
        ]);

        return response()->json(['data' => $support->ask($data['question'], $data['language'] ?? 'pt-BR')])
            ->header('Cache-Control', 'private, no-store');
    }
}
