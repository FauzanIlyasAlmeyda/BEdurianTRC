<?php

namespace App\Http\Controllers\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\ProductStatus;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ContractFormatter;
use App\Models\CollectorShipment;
use App\Models\CollectorShipmentSource;
use App\Models\DistributorSale;
use App\Models\UmkmProfile;
use App\Models\HarvestBatch;

use App\Models\UmkmProduct;
use App\Models\UmkmProductSource;
use App\Models\User;
use App\Support\CodeGenerator;
use App\Support\Traceability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;


class UmkmController extends Controller
{
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        return ApiResponse::success([
            'user' => $user,
            'profile' => UmkmProfile::firstOrCreate([
                'user_id' => $user->id,
            ], [
                'name' => $user->full_name,
                'owner_name' => $user->full_name,
            ]),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'owner_name' => ['nullable', 'string', 'max:150'],
            'about' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'village' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'contact' => ['nullable', 'string', 'max:50'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('image')) {
            $data['image_path'] = $this->storePublicImage($request, 'image', 'uploads/profiles/umkm');
        }
        unset($data['image']);

        $profile = UmkmProfile::updateOrCreate([
            'user_id' => $user->id,
        ], $data + [
            'name' => $data['name'] ?? $user->full_name,
            'owner_name' => $data['owner_name'] ?? $user->full_name,
        ]);

        return ApiResponse::success($profile, 'Profil UMKM diperbarui');
    }

    public function products(Request $request): JsonResponse
    {
        $this->ensureRole($request->user(), UserRole::Umkm->value);

        return ApiResponse::success(
            UmkmProduct::query()
                ->with('sources.batch')
                ->where('umkm_user_id', $request->user()->id)
                ->latest()
                ->get()
                ->map(fn (UmkmProduct $product): array => ContractFormatter::product($product))
                ->values()
                ->all()
        );
    }

    public function batches(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        return ApiResponse::success(HarvestBatch::query()
            ->with('gradeBreakdowns')
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', UserRole::Umkm->value)
            ->where('status', HarvestBatchStatus::Created->value)
            ->latest()
            ->get()
            ->map(fn (HarvestBatch $batch): array => ContractFormatter::batch($batch))
            ->values()
            ->all());
    }

    public function materialBatches(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        $sales = DistributorSale::query()
            ->with(['seller', 'batch'])
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', UserRole::Umkm->value)
            ->where('status', 'verified')
            ->get();
        $shipments = CollectorShipment::query()
            ->with(['collector', 'sources.batch'])
            ->where('destination_type', UserRole::Umkm->value)
            ->where('destination_user_id', $user->id)
            ->where('status', ShipmentStatus::Completed->value)
            ->get();

        $linkedBatchIds = $sales->pluck('batch_id')
            ->merge(
                CollectorShipmentSource::query()
                    ->whereIn('collector_shipment_id', $shipments->pluck('id'))
                    ->pluck('harvest_batch_id')
            )
            ->filter()
            ->unique()
            ->values();

        $directBatches = HarvestBatch::query()
            ->with('gradeBreakdowns')
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', UserRole::Umkm->value)
            ->where('status', HarvestBatchStatus::ReceivedByUmkm->value)
            ->when($linkedBatchIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $linkedBatchIds))
            ->latest()
            ->get()
            ->map(fn (HarvestBatch $batch): array => ContractFormatter::batch($batch));

        $saleBatches = $sales->map(fn (DistributorSale $sale): array => [
            'code' => $sale->code,
            'farmer_id' => (string) $sale->seller_user_id,
            'farm_id' => '',
            'variety' => $sale->batch?->variety ?? 'Durian',
            'grade' => $sale->batch?->grade ?? '-',
            'quantity' => (float) ($sale->received_weight_kg ?? $sale->expected_weight_kg ?? 0),
            'unit' => $sale->batch?->unit ?? 'kg',
            'fruit_count' => $sale->received_fruit_count ?? $sale->expected_fruit_count,
            'harvest_date' => $sale->batch?->harvest_date?->toDateString() ?? $sale->created_at?->toDateString(),
            'farm_name_snapshot' => $sale->seller?->full_name ?? 'Distributor',
            'photo_path' => $sale->batch?->photo_path,
            'photo_url' => ContractFormatter::imageUrl($sale->batch?->photo_path),
            'status' => HarvestBatchStatus::ReceivedByUmkm->value,
            'created_at' => $sale->verified_at?->toISOString() ?? $sale->created_at?->toISOString(),
            'received_quantity_kg' => (float) ($sale->received_weight_kg ?? $sale->expected_weight_kg ?? 0),
            'received_fruit_count' => $sale->received_fruit_count ?? $sale->expected_fruit_count,
            'verified_by' => $user->full_name,
            'verified_by_role' => UserRole::Umkm->value,
            'verified_at' => $sale->verified_at?->toISOString(),
        ]);

        $shipmentBatches = $shipments->map(function (CollectorShipment $shipment) use ($user): array {
            $source = $shipment->sources->first();
            $batch = $source?->batch;
            $quantity = (float) ($shipment->total_weight_kg ?? 0);
            $fruitCount = (int) ($shipment->total_fruit_count ?? 0);

            return [
                'code' => $shipment->code,
                'farmer_id' => $batch?->farmer_id === null ? '' : (string) $batch->farmer_id,
                'farm_id' => $batch?->farm_id === null ? '' : (string) $batch->farm_id,
                'variety' => $source?->source_variety_snapshot ?? $batch?->variety ?? 'Durian',
                'grade' => $batch?->grade ?? '-',
                'quantity' => $quantity,
                'unit' => 'kg',
                'fruit_count' => $fruitCount,
                'harvest_date' => $batch?->harvest_date?->toDateString() ?? $shipment->packaged_at?->toDateString(),
                'farm_name_snapshot' => $shipment->collector?->full_name ?? 'Pengepul',
                'photo_path' => $batch?->photo_path,
                'photo_url' => ContractFormatter::imageUrl($batch?->photo_path),
                'status' => HarvestBatchStatus::ReceivedByUmkm->value,
                'created_at' => $shipment->completed_at?->toISOString() ?? $shipment->packaged_at?->toISOString(),
                'received_quantity_kg' => $quantity,
                'received_fruit_count' => $fruitCount,
                'verified_by' => $user->full_name,
                'verified_by_role' => UserRole::Umkm->value,
                'verified_at' => $shipment->completed_at?->toISOString(),
            ];
        });

        return ApiResponse::success($directBatches
            ->concat($saleBatches)
            ->concat($shipmentBatches)
            ->values()
            ->all());
    }

    public function receiptHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        $farmerReceipts = HarvestBatch::query()
            ->with('farmer')
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', UserRole::Umkm->value)
            ->where('status', HarvestBatchStatus::ReceivedByUmkm->value)
            ->get()
            ->map(fn (HarvestBatch $batch): array => [
                'id' => 'farmer-'.$batch->code,
                'source_type' => UserRole::Petani->value,
                'trace_code' => $batch->code,
                'product_name' => 'Durian '.$batch->variety,
                'supplier_name' => $batch->farmer?->full_name ?? 'Petani',
                'quantity' => (float) ($batch->received_quantity_kg ?? $batch->quantity_kg ?? 0),
                'unit' => $batch->unit,
                'fruit_count' => $batch->received_fruit_count ?? $batch->fruit_count,
                'received_at' => $batch->verified_at?->toISOString(),
                'condition' => null,
                'note' => $batch->quality_notes,
                'photo_path' => $batch->photo_path,
                'photo_url' => ContractFormatter::imageUrl($batch->photo_path),
            ]);

        $saleReceipts = DistributorSale::query()
            ->with(['seller', 'batch'])
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', UserRole::Umkm->value)
            ->where('status', 'verified')
            ->get()
            ->map(fn (DistributorSale $sale): array => [
                'id' => 'sale-'.$sale->code,
                'source_type' => UserRole::Distributor->value,
                'trace_code' => $sale->code,
                'product_name' => 'Durian '.($sale->batch?->variety ?? 'Durian'),
                'supplier_name' => $sale->seller?->full_name ?? 'Distributor',
                'quantity' => (float) ($sale->received_weight_kg ?? $sale->expected_weight_kg ?? 0),
                'unit' => $sale->batch?->unit ?? 'kg',
                'fruit_count' => $sale->received_fruit_count ?? $sale->expected_fruit_count,
                'received_at' => $sale->verified_at?->toISOString(),
                'condition' => $sale->condition,
                'note' => $sale->quality_note ?? $sale->discrepancy_note,
                'photo_path' => $sale->batch?->photo_path,
                'photo_url' => ContractFormatter::imageUrl($sale->batch?->photo_path),
            ]);

        $shipmentReceipts = CollectorShipment::query()
            ->with(['collector', 'sources.batch'])
            ->where('destination_type', UserRole::Umkm->value)
            ->where('destination_user_id', $user->id)
            ->where('status', ShipmentStatus::Completed->value)
            ->get()
            ->map(function (CollectorShipment $shipment): array {
                $source = $shipment->sources->first();
                $variety = $source?->source_variety_snapshot;

                return [
                    'id' => 'shipment-'.$shipment->code,
                    'source_type' => UserRole::Pengepul->value,
                    'trace_code' => $shipment->code,
                    'product_name' => 'Durian '.($variety ?: 'Durian'),
                    'supplier_name' => $shipment->collector?->full_name ?? 'Pengepul',
                    'quantity' => (float) ($shipment->total_weight_kg ?? 0),
                    'unit' => 'kg',
                    'fruit_count' => $shipment->total_fruit_count,
                    'received_at' => $shipment->completed_at?->toISOString(),
                    'condition' => null,
                    'note' => $shipment->warehouse_note,
                    'photo_path' => $source?->batch?->photo_path,
                    'photo_url' => ContractFormatter::imageUrl($source?->batch?->photo_path),
                ];
            });

        $receipts = $farmerReceipts
            ->concat($saleReceipts)
            ->concat($shipmentReceipts)
            ->sortByDesc('received_at')
            ->values()
            ->all();

        return ApiResponse::success($receipts);
    }

    public function incomingShipments(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        return ApiResponse::success(
            CollectorShipment::query()
                ->with('sources.batch')
                ->where('destination_type', UserRole::Umkm->value)
                ->where('destination_user_id', $user->id)
                ->where('status', ShipmentStatus::Sent->value)
                ->latest()
                ->get()
                ->map(fn (CollectorShipment $shipment): array => ContractFormatter::shipment($shipment))
                ->values()
                ->all()
        );
    }

    public function receiveShipment(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        $shipment = DB::transaction(function () use ($user, $code): ?CollectorShipment {
            $shipment = CollectorShipment::query()
                ->where('code', $code)
                ->where('destination_type', UserRole::Umkm->value)
                ->where('destination_user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($shipment->status !== ShipmentStatus::Sent->value) {
                return null;
            }

            $shipment->forceFill([
                'status' => ShipmentStatus::Completed->value,
                'completed_at' => now(),
            ])->save();

            $shipment->load('sources.batch');
            foreach ($shipment->sources as $source) {
                if (! $source->batch) {
                    continue;
                }

                $source->batch->forceFill([
                    'status' => HarvestBatchStatus::ReceivedByUmkm->value,
                    'recipient_user_id' => $user->id,
                    'recipient_role' => UserRole::Umkm->value,
                    'received_quantity_kg' => $source->source_weight_kg,
                    'received_fruit_count' => $source->source_fruit_count,
                    'verified_by_user_id' => $user->id,
                    'verified_at' => now(),
                ])->save();

                Traceability::recordEvent($source->batch, 'Shipment Diterima UMKM', $user, [
                    'status' => HarvestBatchStatus::ReceivedByUmkm->value,
                    'shipment_code' => $shipment->code,
                ]);
            }

            return $shipment->load('sources');
        });

        if (! $shipment) {
            return ApiResponse::error('Shipment hanya bisa diterima saat status sent', 422);
        }

        return ApiResponse::success([
            'code' => $shipment->code,
            'status' => $shipment->status,
            'completed_at' => $shipment->completed_at?->toISOString(),
            'shipment' => ContractFormatter::shipment($shipment),
        ], 'Shipment diterima UMKM');
    }

    public function receiveBatch(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        $batch = HarvestBatch::query()
            ->where('code', $code)
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', UserRole::Umkm->value)
            ->firstOrFail();

        if ($batch->status !== HarvestBatchStatus::Created->value) {
            return ApiResponse::error('Batch hanya bisa diterima saat status created', 422);
        }

        $data = $request->validate([
            'received_quantity_kg' => ['required', 'numeric', 'min:0'],
            'received_fruit_count' => ['required', 'integer', 'min:0'],
            'quality_notes' => ['nullable', 'string'],
        ]);

        DB::transaction(function () use ($batch, $data, $user): void {
            $batch->forceFill([
                'status' => HarvestBatchStatus::ReceivedByUmkm->value,
                'received_quantity_kg' => $data['received_quantity_kg'],
                'received_fruit_count' => $data['received_fruit_count'],
                'quality_notes' => $data['quality_notes'] ?? null,
                'verified_by_user_id' => $user->id,
                'verified_at' => now(),
            ])->save();

            Traceability::recordEvent($batch, 'Batch Diterima UMKM', $user, [
                'status' => HarvestBatchStatus::ReceivedByUmkm->value,
            ]);
        });

        return ApiResponse::success([
            'code' => $batch->code,
            'status' => $batch->status,
            'batch' => ContractFormatter::batch($batch->load('gradeBreakdowns')),
        ], 'Batch diterima UMKM');
    }

    public function storeProduct(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        $data = $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:150'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'price_label' => ['nullable', 'string', 'max:50'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'stock_label' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(ProductStatus::values())],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'image_path' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:4096'],
            'image' => ['nullable', 'image', 'max:4096'],
            'qr_code_data' => ['nullable', 'string', 'max:150'],
            'source_codes' => ['nullable', 'array'],
            'source_codes.*' => ['string', 'exists:harvest_batches,code'],
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('uploads/products', 'public');
        } elseif ($request->hasFile('image')) {
            $data['photo_path'] = $request->file('image')->store('uploads/products', 'public');
        }
        unset($data['photo'], $data['image']);

        $sourceBatches = HarvestBatch::query()
            ->with('farmer')
            ->whereIn('code', $data['source_codes'] ?? [])
            ->get()
            ->keyBy('code');

        if ($sourceBatches->isNotEmpty()) {
            $invalidSourceCodes = $sourceBatches
                ->filter(fn (HarvestBatch $batch): bool => $batch->status !== HarvestBatchStatus::ReceivedByUmkm->value
                    || ($batch->recipient_user_id !== null
                        && ((int) $batch->recipient_user_id !== $user->id
                            || $batch->recipient_role !== UserRole::Umkm->value)))
                ->keys()
                ->values()
                ->all();

            if ($invalidSourceCodes !== []) {
                return ApiResponse::validation([
                    'source_codes' => ['Source batch harus sudah berstatus receivedByUmkm sebelum dipakai membuat produk.'],
                ]);
            }
        }
        $product = DB::transaction(function () use ($user, $data) {
            $price = $this->resolvePrice($data);
            $stockQty = $this->resolveStock($data);
            $photoPath = $data['photo_path'] ?? $data['image_path'] ?? null;

            $product = UmkmProduct::create([
                'code' => CodeGenerator::product(),
                'umkm_user_id' => $user->id,
                'category' => $data['category'] ?? null,
                'name' => $data['name'],
                'price' => $price,
                'stock_qty' => $stockQty,
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? ProductStatus::Aktif->value,
                'photo_path' => $photoPath,
            ]);

            $product->forceFill([
                'code' => CodeGenerator::product($product->id),
            ])->save();

            foreach (($data['source_codes'] ?? []) as $sourceCode) {
                $batch = HarvestBatch::query()->where('code', $sourceCode)->first();
                if (! $batch) {
                    continue;
                }

                UmkmProductSource::create([
                    'umkm_product_id' => $product->id,
                    'harvest_batch_id' => $batch->id,
                    'source_code_snapshot' => $batch->code,
                    'weight_kg' => $batch->quantity_kg,
                    'fruit_count' => $batch->fruit_count,
                ]);

                $batch->forceFill([
                    'status' => HarvestBatchStatus::Processed->value,
                ])->save();

                Traceability::recordEvent($batch, 'Source batch dipakai untuk produk UMKM', $user, [
                    'status' => HarvestBatchStatus::Processed->value,
                    'product_code' => $product->code,
                ]);
            }

            return $product;
        });

        return ApiResponse::created(
            ContractFormatter::product($product->load('sources.batch')),
            'Produk berhasil dibuat'
        );
    }

    public function updateProduct(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        $product = UmkmProduct::query()
            ->where('code', $code)
            ->where('umkm_user_id', $user->id)
            ->firstOrFail();

        $data = $request->validate([
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'name' => ['sometimes', 'required', 'string', 'max:150'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'price_label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'stock_qty' => ['sometimes', 'integer', 'min:0'],
            'stock_label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'required', Rule::in(ProductStatus::values())],
            'photo_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'image_path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'image', 'max:4096'],
            'image' => ['nullable', 'image', 'max:4096'],
        ]);

        if ($request->hasFile('photo')) {
            $data['photo_path'] = $request->file('photo')->store('uploads/products', 'public');
        } elseif ($request->hasFile('image')) {
            $data['photo_path'] = $request->file('image')->store('uploads/products', 'public');
        }

        unset($data['photo'], $data['image'], $data['price_label'], $data['stock_label']);

        if (! array_key_exists('price', $data) && $request->exists('price_label')) {
            $data['price'] = $this->resolveMoney($request->input('price_label'));
        }
        if (! array_key_exists('stock_qty', $data) && $request->exists('stock_label')) {
            $data['stock_qty'] = $this->resolveStock([
                'stock_label' => $request->input('stock_label'),
            ]);
        }
        if (array_key_exists('image_path', $data) && ! array_key_exists('photo_path', $data)) {
            $data['photo_path'] = $data['image_path'];
        }
        unset($data['image_path']);

        DB::transaction(function () use ($product, $data): void {
            $lockedProduct = UmkmProduct::query()
                ->whereKey($product->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedProduct->fill($data)->save();
        });

        return ApiResponse::success(
            ContractFormatter::product($product->fresh()->load('sources.batch')),
            'Produk berhasil diperbarui'
        );
    }

    public function destroyProduct(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Umkm->value);

        $product = UmkmProduct::query()
            ->where('code', $code)
            ->where('umkm_user_id', $user->id)
            ->firstOrFail();

        DB::transaction(function () use ($product, $user): void {
            $sourceBatches = $product->sources()
                ->with('batch')
                ->get()
                ->pluck('batch')
                ->filter();

            $product->delete();

            foreach ($sourceBatches as $batch) {
                $stillUsed = UmkmProductSource::query()
                    ->where('harvest_batch_id', $batch->id)
                    ->exists();
                if ($stillUsed
                    || $batch->status !== HarvestBatchStatus::Processed->value
                    || (int) $batch->recipient_user_id !== $user->id
                    || $batch->recipient_role !== UserRole::Umkm->value) {
                    continue;
                }

                $batch->forceFill([
                    'status' => HarvestBatchStatus::ReceivedByUmkm->value,
                ])->save();

                Traceability::recordEvent($batch, 'Produk UMKM dihapus; batch bahan baku tersedia kembali', $user, [
                    'status' => HarvestBatchStatus::ReceivedByUmkm->value,
                    'product_code' => $product->code,
                ]);
            }
        });

        return ApiResponse::success(null, 'Produk berhasil dihapus');
    }

    private function ensureRole(?User $user, string $role): void
    {
        abort_unless($user && $user->role === $role, 403, 'Forbidden');
    }

    private function storePublicImage(Request $request, string $field, string $directory): string
    {
        $path = $request->file($field)->store($directory, 'public');

        return '/storage/' . $path;
    }

    private function resolvePrice(array $data): float
    {
        if (isset($data['price']) && $data['price'] !== null) {
            return (float) $data['price'];
        }

        return $this->resolveMoney($data['price_label'] ?? null);
    }

    private function resolveStock(array $data): int
    {
        if (isset($data['stock_qty']) && $data['stock_qty'] !== null) {
            return (int) $data['stock_qty'];
        }

        if (empty($data['stock_label'])) {
            return 0;
        }

        preg_match('/(\d+)/', (string) $data['stock_label'], $matches);

        return isset($matches[1]) ? (int) $matches[1] : 0;
    }

    private function resolveMoney(?string $label): float
    {
        if ($label === null) {
            return 0.0;
        }

        $normalized = preg_replace('/[^\d,\.]/', '', $label);
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);

        return (float) $normalized;
    }
}
