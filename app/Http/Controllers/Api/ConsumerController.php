<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProductStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ContractFormatter;
use App\Models\ConsumerProfile;
use App\Models\UmkmProduct;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsumerController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Konsumen->value);

        return ApiResponse::success([
            'user' => $user,
            'profile' => ConsumerProfile::firstOrCreate([
                'user_id' => $user->id,
            ], [
                'display_name' => $user->full_name,
            ]),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Konsumen->value);

        $data = $request->validate([
            'display_name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'village' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:50'],
            'avatar_path' => ['nullable', 'string', 'max:255'],
        ]);

        $profile = ConsumerProfile::updateOrCreate([
            'user_id' => $user->id,
        ], $data + [
            'display_name' => $data['display_name'] ?? $user->full_name,
        ]);

        return ApiResponse::success($profile, 'Profil konsumen diperbarui');
    }

    public function products(Request $request): JsonResponse
    {
        $this->ensureRole($request->user(), UserRole::Konsumen->value);

        return ApiResponse::success(
            UmkmProduct::query()
                ->with(['umkm.umkmProfile'])
                ->where('status', ProductStatus::Aktif->value)
                ->latest()
                ->get()
                ->map(fn (UmkmProduct $product): array => $this->consumerProduct($product))
                ->values()
                ->all()
        );
    }

    public function showProduct(Request $request, string $code): JsonResponse
    {
        $this->ensureRole($request->user(), UserRole::Konsumen->value);

        $product = UmkmProduct::query()
            ->with('sources.batch')
            ->where('code', $code)
            ->firstOrFail();

        $sourceBatch = $product->sources->first();

        return ApiResponse::success([
            'product' => array_merge(
                ContractFormatter::product($product),
                [
                    'source_batch_code' => $sourceBatch?->source_code_snapshot,
                    'source_variety' => $sourceBatch?->batch?->variety,
                    'source_grade' => $sourceBatch?->batch?->grade,
                    'source_origin_farm' => $sourceBatch?->batch?->farm_name_snapshot,
                    'source_harvest_date' => $sourceBatch?->batch?->harvest_date?->toDateString(),
                    'source_harvest_method' => $sourceBatch?->batch?->harvest_method,
                    'source_maturity_level' => $sourceBatch?->batch?->maturity_level,
                    'source_shelf_life_estimate' => $sourceBatch?->batch?->shelf_life_estimate,
                    'source_verified_at' => $sourceBatch?->batch?->verified_at?->toISOString(),
                    'source_quality_notes' => $sourceBatch?->batch?->quality_notes,
                ],
            ),
        ]);
    }

    private function ensureRole(?User $user, string $role): void
    {
        abort_unless($user && $user->role === $role, 403, 'Forbidden');
    }

    private function consumerProduct(UmkmProduct $product): array
    {
        $profile = $product->umkm?->umkmProfile;

        return [
            'code' => $product->code,
            'name' => $product->name,
            'category' => $product->category ?? 'Paket',
            'status' => 'readyToSell',
            'price_label' => ContractFormatter::moneyLabel($product->price),
            'short_description' => $product->description ? str($product->description)->limit(80)->toString() : null,
            'umkm_name' => $profile?->name ?? $product->umkm?->full_name,
            'location' => $profile?->address,
            'rating' => 4.9,
            'stock_label' => 'Stok ' . (int) $product->stock_qty . ' paket',
            'photo_path' => $product->photo_path,
            'image_path' => $product->photo_path,
            'photo_url' => ContractFormatter::imageUrl($product->photo_path),
            'image_url' => ContractFormatter::imageUrl($product->photo_path),
        ];
    }

}
