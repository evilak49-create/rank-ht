<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PreventClientApiCaching
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // These same-origin routes can issue session and CSRF cookies, including public reads.
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
