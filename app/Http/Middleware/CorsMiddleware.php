<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CorsMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $origin = $request->header('Origin');
        $allowedOrigins = [
            'https://duriantrace.id',
            'http://localhost:5631',
            'http://localhost:6295',
            'http://localhost:8080',
            'http://localhost:3000',
            'http://127.0.0.1:5631',
            'http://127.0.0.1:6295',
            'http://127.0.0.1:8080',
            'http://127.0.0.1:3000',
        ];

        $originParts = is_string($origin) ? parse_url($origin) : false;
        $isLocalDevelopmentOrigin = is_array($originParts)
            && ($originParts['scheme'] ?? null) === 'http'
            && in_array(
                $originParts['host'] ?? '',
                ['localhost', '127.0.0.1'],
                true,
            );
        $isAllowedOrigin = is_string($origin)
            && (in_array($origin, $allowedOrigins, true) || $isLocalDevelopmentOrigin);

        $response = $request->isMethod('OPTIONS')
            ? response()->noContent()
            : $next($request);

        if ($isAllowedOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, Accept');
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
            $response->headers->set('Access-Control-Max-Age', '86400');
        }

        return $response;
    }
}
