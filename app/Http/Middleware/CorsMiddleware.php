<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        // Allow dari localhost development
        $origin = $request->header('Origin');
        $allowedOrigins = [
            'http://localhost:5631',
            'http://localhost:6295',
            'http://localhost:8080',
            'http://localhost:3000',
            'http://127.0.0.1:5631',
            'http://127.0.0.1:6295',
            'http://127.0.0.1:8080',
            'http://127.0.0.1:3000',
        ];

        if (in_array($origin, $allowedOrigins)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
        $response->headers->set('Access-Control-Allow-Credentials', 'true');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
