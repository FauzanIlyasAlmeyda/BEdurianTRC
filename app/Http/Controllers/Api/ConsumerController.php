<?php

namespace App\Http\Controllers\Api;

use App\Enums\ProductStatus;
use App\Enums\ConsumerTransactionStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ContractFormatter;
use App\Support\ProfileAvatarStorage;
use App\Models\ConsumerProfile;
use App\Models\ConsumerTransaction;
use App\Models\UmkmProduct;
use App\Models\User;
use App\Support\CodeGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        ]);

        $profile = ConsumerProfile::updateOrCreate([
            'user_id' => $user->id,
        ], $data + [
            'display_name' => $data['display_name'] ?? $user->full_name,
        ]);

        return ApiResponse::success($profile, 'Profil konsumen diperbarui');
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Konsumen->value);
        ProfileAvatarStorage::validate($request);

        $profile = ConsumerProfile::firstOrCreate([
            'user_id' => $user->id,
        ], [
            'display_name' => $user->full_name,
        ]);
        $profile->avatar_path = ProfileAvatarStorage::update($request, $profile->avatar_path);
        $profile->save();

        return ApiResponse::success($profile, 'Foto profil konsumen diperbarui');
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
            ->with(['sources.batch.events', 'umkm.umkmProfile'])
            ->where('code', $code)
            ->where('status', ProductStatus::Aktif->value)
            ->firstOrFail();

        return ApiResponse::success([
            'product' => array_merge(
                ContractFormatter::product($product),
                [
                    'trace_sources' => $this->productTraceSources($product),
                ],
            ),
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Konsumen->value);

        return ApiResponse::success(
            ConsumerTransaction::query()
                ->where('consumer_user_id', $user->id)
                ->latest()
                ->get()
                ->map(fn (ConsumerTransaction $transaction): array => $this->orderPayload($transaction))
                ->values()
                ->all()
        );
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Konsumen->value);

        $data = $request->validate([
            'product_code' => ['required', 'string', 'exists:umkm_products,code'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $transaction = DB::transaction(function () use ($user, $data): ConsumerTransaction {
            $product = UmkmProduct::query()
                ->with(['sources.batch.events', 'umkm.umkmProfile'])
                ->where('code', $data['product_code'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($product->status !== ProductStatus::Aktif->value) {
                throw ValidationException::withMessages([
                    'product_code' => ['Produk sedang tidak aktif.'],
                ]);
            }

            if ((int) $product->stock_qty < (int) $data['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => ['Jumlah melebihi stok produk yang tersedia.'],
                ]);
            }

            $traceSnapshot = [
                'product_code' => $product->code,
                'product_name' => $product->name,
                'umkm_name' => $product->umkm?->umkmProfile?->name
                    ?? $product->umkm?->full_name,
                'sources' => $this->productTraceSources($product),
            ];

            $transaction = ConsumerTransaction::create([
                'code' => CodeGenerator::transaction(),
                'consumer_user_id' => $user->id,
                'umkm_product_id' => $product->id,
                'product_name_snapshot' => $product->name,
                'quantity' => $data['quantity'],
                'total_amount' => (float) $product->price * (int) $data['quantity'],
                'buyer_address' => ConsumerProfile::query()
                    ->where('user_id', $user->id)
                    ->value('address'),
                'payment_method' => 'outside_app',
                'payment_status' => 'outside_app',
                'status' => ConsumerTransactionStatus::Processing->value,
                'trace_snapshot' => $traceSnapshot,
            ]);

            $product->forceFill([
                'stock_qty' => (int) $product->stock_qty - (int) $data['quantity'],
            ])->save();

            return $transaction;
        });

        return ApiResponse::created(
            $this->orderPayload($transaction),
            'Pesanan berhasil dibuat'
        );
    }

    public function cancelOrder(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Konsumen->value);

        $transaction = DB::transaction(function () use ($user, $code): ?ConsumerTransaction {
            $transaction = ConsumerTransaction::query()
                ->where('code', $code)
                ->where('consumer_user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->status !== ConsumerTransactionStatus::Processing->value) {
                return null;
            }

            $transaction->forceFill([
                'status' => ConsumerTransactionStatus::Cancelled->value,
            ])->save();

            if ($transaction->umkm_product_id !== null) {
                $product = UmkmProduct::query()
                    ->whereKey($transaction->umkm_product_id)
                    ->lockForUpdate()
                    ->first();

                if ($product) {
                    $product->increment('stock_qty', (int) $transaction->quantity);
                }
            }

            return $transaction;
        });

        if (! $transaction) {
            return ApiResponse::error('Pesanan ini tidak dapat dibatalkan', 422);
        }

        return ApiResponse::success(
            $this->orderPayload($transaction),
            'Pesanan dibatalkan dan stok dikembalikan'
        );
    }

    public function validateOrder(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Konsumen->value);

        $transaction = DB::transaction(function () use ($user, $code): ?ConsumerTransaction {
            $transaction = ConsumerTransaction::query()
                ->where('code', $code)
                ->where('consumer_user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->status !== ConsumerTransactionStatus::Processing->value) {
                return null;
            }

            $transaction->forceFill([
                'status' => ConsumerTransactionStatus::Completed->value,
                'processed_at' => now(),
            ])->save();

            return $transaction;
        });

        if (! $transaction) {
            return ApiResponse::error('Pesanan ini tidak dapat divalidasi', 422);
        }

        return ApiResponse::success(
            $this->orderPayload($transaction),
            'Pesanan divalidasi; struk trace produk tersedia'
        );
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
            'status' => (int) $product->stock_qty > 0 ? 'readyToSell' : 'soldOut',
            'price_label' => ContractFormatter::moneyLabel($product->price),
            'short_description' => $product->description ? str($product->description)->limit(80)->toString() : null,
            'umkm_name' => $profile?->name ?? $product->umkm?->full_name,
            'location' => $profile?->address,
            'rating' => 4.9,
            'stock_label' => 'Stok ' . (int) $product->stock_qty . ' paket',
            'stock_qty' => (int) $product->stock_qty,
            'price' => (float) $product->price,
            'photo_path' => $product->photo_path,
            'image_path' => $product->photo_path,
            'photo_url' => ContractFormatter::imageUrl($product->photo_path),
            'image_url' => ContractFormatter::imageUrl($product->photo_path),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function productTraceSources(UmkmProduct $product): array
    {
        return $product->sources
            ->map(function ($source): array {
                $batch = $source->batch;

                return [
                    'batch_code' => $source->source_code_snapshot,
                    'variety' => $batch?->variety,
                    'grade' => $batch?->grade,
                    'farm_name' => $batch?->farm_name_snapshot,
                    'farmer_name' => $batch?->farmer?->full_name,
                    'harvest_date' => $batch?->harvest_date?->toDateString(),
                    'timeline' => $batch
                        ? ContractFormatter::batchTimeline($batch->loadMissing('events'))
                        : [],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(ConsumerTransaction $transaction): array
    {
        $trace = $transaction->trace_snapshot ?? [];

        return [
            'code' => $transaction->code,
            'id' => $transaction->code,
            'status' => $transaction->status,
            'product_code' => $trace['product_code'] ?? null,
            'product_name' => $transaction->product_name_snapshot
                ?? $trace['product_name']
                ?? 'Produk UMKM',
            'umkm_name' => $trace['umkm_name'] ?? null,
            'quantity' => (int) $transaction->quantity,
            'total_amount' => (float) $transaction->total_amount,
            'total_label' => ContractFormatter::moneyLabel($transaction->total_amount),
            'buyer_address' => $transaction->buyer_address,
            'payment_status' => $transaction->payment_status,
            'created_at' => $transaction->created_at?->toISOString(),
            'processed_at' => $transaction->processed_at?->toISOString(),
            'qr_code_data' => $transaction->qr_code_data ?? $transaction->code,
            'trace_sources' => $trace['sources'] ?? [],
        ];
    }

}
