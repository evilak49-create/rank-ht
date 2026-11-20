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
            'history' => ['sometimes', 'array', 'list', 'max:6'],
            'history.*' => ['required', 'array:question,answer'],
            'history.*.question' => ['required', 'string', 'max:500'],
            'history.*.answer' => ['required', 'string', 'max:1400'],
        ]);

        return response()->json(['data' => $support->ask($data['question'], $data['language'] ?? 'pt-BR', $data['history'] ?? [])])
            ->header('Cache-Control', 'private, no-store');
    }
}
