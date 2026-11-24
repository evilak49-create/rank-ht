<?php

namespace App\Http\Controllers;

use App\SupportDesk\CustomerSupport;
use App\SupportDesk\SupportConversations;
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

    public function conversation(Request $request, SupportConversations $conversations): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:1', 'prohibits:before'],
            'before' => ['nullable', 'integer', 'min:1', 'prohibits:after'],
        ]);

        return response()->json(['data' => $conversations->visitorData(
            $conversations->forVisitor($request),
            isset($data['after']) ? (int) $data['after'] : null,
            isset($data['before']) ? (int) $data['before'] : null,
        )])->header('Cache-Control', 'private, no-store');
    }

    public function chat(Request $request, SupportConversations $conversations): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:500'],
            'language' => ['nullable', 'in:pt-BR,zh-CN'],
            'request_id' => ['nullable', 'uuid'],
            'conversation_id' => ['nullable', 'uuid'],
            'history' => ['sometimes', 'array', 'list', 'max:6'],
            'history.*' => ['required', 'array:question,answer'],
            'history.*.question' => ['required', 'string', 'max:500'],
            'history.*.answer' => ['required', 'string', 'max:1400'],
        ]);

        $conversation = $conversations->forVisitor($request, create: true);
        abort_if(isset($data['conversation_id']) && $data['conversation_id'] !== $conversation->public_id, 409, '会话已更换，请刷新后重试。');
        $reply = $conversations->send(
            $conversation, $data['question'], $data['language'] ?? 'pt-BR',
            isset($data['request_id']) ? strtolower($data['request_id']) : null, $data['history'] ?? [],
        );

        return response()->json(['data' => $reply, 'conversation' => $conversations->visitorData($conversation)])
            ->header('Cache-Control', 'private, no-store');
    }
}
