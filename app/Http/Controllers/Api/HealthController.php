<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (Throwable $exception) {
            Log::error('Health check database connection failed.', [
                'exception' => $exception::class,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Service is degraded',
                'data' => [
                    'status' => 'error',
                    'services' => [
                        'server' => 'ok',
                        'database' => 'error',
                    ],
                ],
            ], 503);
        }

        return response()->json([
            'success' => true,
            'message' => 'Service is healthy',
            'data' => [
                'status' => 'ok',
                'services' => [
                    'server' => 'ok',
                    'database' => 'ok',
                ],
            ],
        ]);
    }
}
