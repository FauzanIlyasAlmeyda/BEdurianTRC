<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\ConsumerProductValidation;
use App\Models\UmkmProduct;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsumerProductValidationController extends Controller
{
    public function show(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->role === UserRole::Konsumen->value, 403, 'Forbidden');

        $product = UmkmProduct::query()->where('code', $code)->firstOrFail();
        $validation = ConsumerProductValidation::query()
            ->where('umkm_product_id', $product->id)
            ->where('consumer_user_id', $user->id)
            ->first();

        return ApiResponse::success([
            'validated' => $validation !== null,
            'validated_at' => $validation?->validated_at?->toISOString(),
        ]);
    }

    public function store(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->role === UserRole::Konsumen->value, 403, 'Forbidden');
        $request->validate(['confirmation' => ['required', 'accepted']]);

        $product = UmkmProduct::query()->where('code', $code)->firstOrFail();
        $validation = ConsumerProductValidation::query()->firstOrCreate(
            [
                'umkm_product_id' => $product->id,
                'consumer_user_id' => $user->id,
            ],
            ['validated_at' => now()],
        );

        return ApiResponse::success([
            'validated' => true,
            'validated_at' => $validation->validated_at?->toISOString(),
        ], 'Konfirmasi produk berhasil disimpan.');
    }
}
