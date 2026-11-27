<?php

namespace App\Http\Controllers;

use App\Pwa\PwaConfiguration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PwaController
{
    public const PREFIX = '/api/v1/pwa';

    public function config(PwaConfiguration $pwa): JsonResponse
    {
        $data = $pwa->read();

        return response()->json([
            'enabled' => $data['enabled'],
            'host' => $data['host'],
            'theme_color' => $data['theme_color'],
            'icon_version' => $pwa->icon(),
            'copy' => [
                'zh-CN' => ['message' => $data['message_zh'], 'button' => $data['button_zh'], 'offline' => $data['offline_zh']],
                'pt-BR' => ['message' => $data['message_pt'], 'button' => $data['button_pt'], 'offline' => $data['offline_pt']],
            ],
        ]);
    }

    public function manifest(PwaConfiguration $pwa): JsonResponse
    {
        $data = $pwa->read();
        $version = $pwa->icon();
        $icons = [
            ['variant' => '192', 'sizes' => '192x192', 'purpose' => 'any'],
            ['variant' => '512', 'sizes' => '512x512', 'purpose' => 'any'],
            ['variant' => 'maskable', 'sizes' => '512x512', 'purpose' => 'maskable'],
        ];

        return response()->json([
            'id' => '/',
            'name' => $data['name'],
            'short_name' => $data['short_name'],
            'start_url' => $data['start_path'] === '/' ? '/' : '/#'.$data['start_path'],
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => $data['theme_color'],
            'background_color' => $data['background_color'],
            'icons' => array_map(fn (array $icon): array => [
                'src' => self::PREFIX.'/icons/'.$icon['variant'].'.png?v='.$version,
                'sizes' => $icon['sizes'],
                'purpose' => $icon['purpose'],
                'type' => 'image/png',
            ], $icons),
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    public function icon(PwaConfiguration $pwa, string $variant): Response
    {
        return response($pwa->icon($variant), 200, ['Content-Type' => 'image/png']);
    }

    public function worker(): Response
    {
        return response(file_get_contents(resource_path('pwa/worker.js')), 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    public function offline(PwaConfiguration $pwa): Response
    {
        return response()->view('pwa.offline', ['settings' => $pwa->read()], 200, [
            'X-QVIP-Offline' => '1',
        ]);
    }
}
