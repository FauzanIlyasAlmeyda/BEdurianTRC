<?php

namespace App\Http\Controllers\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\ShipmentDestination;
use App\Enums\ShipmentStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use App\Support\ContractFormatter;
use App\Support\ProfileAvatarStorage;
use App\Models\CollectorProfile;
use App\Models\CollectorShipment;
use App\Models\CollectorShipmentSource;
use App\Models\HarvestBatch;
use App\Models\HarvestBatchGradeBreakdown;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\CodeGenerator;
use App\Support\Traceability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CollectorController extends Controller
{
    public function warehouses(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        return ApiResponse::success(
            Warehouse::query()->where('owner_user_id', $user->id)
                ->where('owner_role', UserRole::Pengepul->value)
                ->orderByDesc('is_default')->oldest()->get()
                ->map(fn (Warehouse $warehouse): array => $this->warehousePayload($warehouse))->values()->all()
        );
    }

    /** Daftar akun tujuan pengiriman yang benar-benar terdaftar dan aktif. */
    public function shipmentRecipients(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        $request->merge(['search' => trim((string) $request->input('search'))]);
        $data = $request->validate([
            'role' => ['required', Rule::in([
                'collector',
                UserRole::Pengepul->value,
                UserRole::Distributor->value,
                UserRole::Umkm->value,
                UserRole::Konsumen->value,
            ])],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $lookupRole = $data['role'] === 'collector'
            ? UserRole::Pengepul->value
            : $data['role'];
        $search = trim((string) ($data['search'] ?? ''));
        $profileRelation = match ($lookupRole) {
            UserRole::Pengepul->value => 'collectorProfile',
            UserRole::Distributor->value => 'distributorProfile',
            UserRole::Umkm->value => 'umkmProfile',
            UserRole::Konsumen->value => 'consumerProfile',
        };
        $profileNameColumn = match ($lookupRole) {
            UserRole::Pengepul->value, UserRole::Distributor->value => 'business_name',
            UserRole::Umkm->value => 'name',
            UserRole::Konsumen->value => 'display_name',
        };

        $query = User::query()->where('is_active', true)->whereKeyNot($user->id)
            ->where('role', $lookupRole);

        if ($search !== '') {
            $query->where(function ($query) use ($search, $profileRelation, $profileNameColumn): void {
                $query->where('public_account_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereHas($profileRelation, fn ($profileQuery) => $profileQuery
                        ->where($profileNameColumn, 'like', "%{$search}%"));
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            });
        }

        return ApiResponse::success(
            $query
                ->with(['distributorProfile', 'umkmProfile', 'consumerProfile', 'collectorProfile'])
                ->orderBy('first_name')->limit(50)->get()->map(function (User $recipient): array {
                    $profile = match ($recipient->role) {
                        UserRole::Distributor->value => $recipient->distributorProfile,
                        UserRole::Umkm->value => $recipient->umkmProfile,
                        UserRole::Konsumen->value => $recipient->consumerProfile,
                        default => $recipient->collectorProfile,
                    };
                    $name = match ($recipient->role) {
                        UserRole::Distributor->value, UserRole::Pengepul->value => $profile?->business_name,
                        UserRole::Umkm->value => $profile?->name,
                        UserRole::Konsumen->value => $profile?->display_name,
                        default => null,
                    };
                    return [
                        'userId' => (string) $recipient->id,
                        'accountId' => (string) ($recipient->public_account_id ?? $recipient->id),
                        'name' => filled($name) ? $name : $recipient->full_name,
                        'address' => $profile?->address ?? '',
                        'destinationType' => $recipient->role === UserRole::Pengepul->value ? 'collector' : $recipient->role,
                    ];
                })->values()->all()
        );
    }

    public function storeWarehouse(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);
        $data = $this->validateWarehouse($request);

        $warehouse = DB::transaction(function () use ($user, $data): Warehouse {
            $isDefault = ($data['is_default'] ?? false) || !Warehouse::query()
                ->where('owner_user_id', $user->id)->where('owner_role', UserRole::Pengepul->value)->exists();
            if ($isDefault) {
                Warehouse::query()->where('owner_user_id', $user->id)
                    ->where('owner_role', UserRole::Pengepul->value)->update(['is_default' => false]);
            }
            return Warehouse::create($data + [
                'owner_user_id' => $user->id,
                'owner_role' => UserRole::Pengepul->value,
                'is_default' => $isDefault,
            ]);
        });

        return ApiResponse::created($this->warehousePayload($warehouse), 'Gudang berhasil ditambahkan');
    }

    public function updateWarehouse(Request $request, Warehouse $warehouse): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);
        abort_unless($warehouse->owner_user_id === $user->id && $warehouse->owner_role === UserRole::Pengepul->value, 404);
        $data = $this->validateWarehouse($request);

        DB::transaction(function () use ($user, $warehouse, $data): void {
            $isDefault = $data['is_default'] ?? $warehouse->is_default;
            if ($isDefault) {
                Warehouse::query()->where('owner_user_id', $user->id)
                    ->where('owner_role', UserRole::Pengepul->value)->whereKeyNot($warehouse->id)->update(['is_default' => false]);
            }
            $warehouse->update($data + ['is_default' => $isDefault]);
        });

        return ApiResponse::success($this->warehousePayload($warehouse->refresh()), 'Gudang berhasil diperbarui');
    }

    public function destroyWarehouse(Request $request, Warehouse $warehouse): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);
        abort_unless($warehouse->owner_user_id === $user->id && $warehouse->owner_role === UserRole::Pengepul->value, 404);
        abort_if(Warehouse::query()->where('owner_user_id', $user->id)->where('owner_role', UserRole::Pengepul->value)->count() <= 1, 422, 'Minimal satu gudang harus tersedia.');
        abort_if(HarvestBatch::query()->where('warehouse_id', $warehouse->id)->exists(), 422, 'Gudang masih dipakai oleh batch.');
        $warehouse->delete();
        return ApiResponse::success(null, 'Gudang berhasil dihapus');
    }
    public function profile(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        return ApiResponse::success([
            'user' => $user,
            'profile' => CollectorProfile::firstOrCreate([
                'user_id' => $user->id,
            ], [
                'business_name' => $user->full_name,
            ]),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        $data = $request->validate([
            'business_name' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string'],
            'village' => ['required', 'string', 'max:100'],
            'district' => ['required', 'string', 'max:100'],
            'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'],
            'contact' => ['nullable', 'string', 'max:50'],
        ]);

        $profile = CollectorProfile::updateOrCreate([
            'user_id' => $user->id,
        ], $data + [
            'business_name' => $data['business_name'] ?? $user->full_name,
        ]);

        return ApiResponse::success([
            'user' => $user->refresh(),
            'profile' => $profile,
        ], 'Profil pengepul diperbarui');
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);
        ProfileAvatarStorage::validate($request);

        $profile = CollectorProfile::firstOrCreate([
            'user_id' => $user->id,
        ], [
            'business_name' => $user->full_name,
        ]);
        $profile->avatar_path = ProfileAvatarStorage::update($request, $profile->avatar_path);
        $profile->save();

        return ApiResponse::success([
            'user' => $user->refresh(),
            'profile' => $profile,
        ], 'Foto profil pengepul diperbarui');
    }

    public function stock(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        $batches = HarvestBatch::query()
            ->with(['farm', 'events'])
            ->where('status', HarvestBatchStatus::VerifiedByCollector->value)
            ->where('recipient_user_id', $user->id)
            ->whereIn('recipient_role', ['collector', UserRole::Pengepul->value])
            ->latest()
            ->get();

        return ApiResponse::success([
            'active_batch_count' => $batches->count(),
            'total_weight_kg' => (float) $batches->sum(
                fn (HarvestBatch $batch) => $batch->received_quantity_kg ?? $batch->quantity_kg
            ),
            'total_fruit_count' => (int) $batches->sum(
                fn (HarvestBatch $batch) => $batch->received_fruit_count ?? $batch->fruit_count
            ),
            'grade_breakdown' => $batches
                ->groupBy(fn (HarvestBatch $batch): ?string => $batch->verified_grade ?: $batch->grade)
                ->map(function ($group, $grade): array {
                    return [
                        'key' => $grade,
                        'label' => 'Grade ' . $grade,
                        'total_weight_kg' => (float) $group->sum(
                            fn (HarvestBatch $batch) => $batch->received_quantity_kg ?? $batch->quantity_kg
                        ),
                        'total_fruit_count' => (int) $group->sum(
                            fn (HarvestBatch $batch) => $batch->received_fruit_count ?? $batch->fruit_count
                        ),
                        'batch_count' => $group->count(),
                    ];
                })
                ->values()
                ->all(),
            'variety_breakdown' => $batches
                ->groupBy(fn (HarvestBatch $batch): string => strtolower((string) $batch->variety))
                ->map(function ($group, $variety): array {
                    return [
                        'key' => $variety,
                        'label' => $group->first()?->variety ? 'Durian ' . $group->first()->variety : $variety,
                        'total_weight_kg' => (float) $group->sum(
                            fn (HarvestBatch $batch) => $batch->received_quantity_kg ?? $batch->quantity_kg
                        ),
                        'total_fruit_count' => (int) $group->sum(
                            fn (HarvestBatch $batch) => $batch->received_fruit_count ?? $batch->fruit_count
                        ),
                        'batch_count' => $group->count(),
                    ];
                })
                ->values()
                ->all(),
        ]);
    }

    public function shipmentBatches(Request $request): JsonResponse
    {
        $this->ensureRole($request->user(), UserRole::Pengepul->value);

        return ApiResponse::success(
            CollectorShipment::query()
                ->with('sources.batch')
                ->where('collector_user_id', $request->user()->id)
                ->latest()
                ->get()
                ->map(fn (CollectorShipment $shipment): array => ContractFormatter::shipment($shipment))
                ->values()
                ->all()
        );
    }

    public function batches(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        return ApiResponse::success(HarvestBatch::query()->with(['gradeBreakdowns', 'farmer'])
            ->whereIn('status', [HarvestBatchStatus::Created->value, HarvestBatchStatus::VerifiedByCollector->value])
            ->where('recipient_user_id', $user->id)
            ->whereIn('recipient_role', ['collector', UserRole::Pengepul->value])
            ->latest()->get()->map(fn (HarvestBatch $batch): array => ContractFormatter::batch($batch))->values()->all());
    }

    public function storeShipmentBatches(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        $data = $request->validate([
            'destination_type' => ['required', Rule::in(ShipmentDestination::values())],
            'destination_user_id' => ['required', 'integer', 'exists:users,id'],
            'destination_name' => ['required', 'string', 'max:150'],
            'destination_location' => ['required', 'string'],
            'source_batch_codes' => ['nullable', 'array', 'min:1'],
            'source_batch_codes.*' => ['distinct'],
            'source_batch_codes.*' => ['string', 'exists:harvest_batches,code'],
            'batch_codes' => ['nullable', 'array', 'min:1'],
            'batch_codes.*' => ['distinct'],
            'batch_codes.*' => ['string', 'exists:harvest_batches,code'],
            'warehouse_note' => ['nullable', 'string'],
        ]);

        $sourceCodes = array_values(array_unique($data['source_batch_codes'] ?? $data['batch_codes'] ?? []));

        $batches = HarvestBatch::query()
            ->whereIn('code', $sourceCodes)
            ->get()
            ->keyBy('code');

        if ($batches->isEmpty()) {
            return ApiResponse::validation([
                'source_batch_codes' => ['Minimal satu batch harus dipilih.'],
            ]);
        }

        $invalidSourceCodes = $batches
            ->filter(fn (HarvestBatch $batch): bool => $batch->status !== HarvestBatchStatus::VerifiedByCollector->value
                || ! $this->isCollectorBatchAccessible($batch, $user))
            ->keys()
            ->values()
            ->all();

        if ($invalidSourceCodes !== []) {
            return ApiResponse::validation([
                'source_batch_codes' => [
                    'Batch hanya bisa masuk shipment jika sudah diverifikasi pengepul.',
                ],
            ]);
        }

        $shipment = DB::transaction(function () use ($user, $data, $batches) {
            $shipment = CollectorShipment::create([
                'code' => CodeGenerator::shipment(),
                'collector_user_id' => $user->id,
                'destination_type' => $data['destination_type'],
                'destination_user_id' => $data['destination_user_id'],
                'destination_name' => $data['destination_name'],
                'destination_location' => $data['destination_location'],
                'total_weight_kg' => $batches->sum('quantity_kg'),
                'total_fruit_count' => $batches->sum('fruit_count') ?: 0,
                'packaged_at' => now(),
                'warehouse_note' => $data['warehouse_note'] ?? null,
                'status' => ShipmentStatus::ReadyToShip->value,
            ]);

            $shipment->forceFill([
                'code' => CodeGenerator::shipment($shipment->id),
            ])->save();

            foreach ($batches as $batch) {
                CollectorShipmentSource::create([
                    'collector_shipment_id' => $shipment->id,
                    'harvest_batch_id' => $batch->id,
                    'source_code_snapshot' => $batch->code,
                    'source_grade_snapshot' => $batch->grade,
                    'source_weight_kg' => $batch->quantity_kg,
                    'source_fruit_count' => $batch->fruit_count,
                    'source_variety_snapshot' => $batch->variety,
                ]);
            }

            return $shipment;
        });

        foreach ($batches as $batch) {
            $batch->forceFill([
                'status' => HarvestBatchStatus::InDistribution->value,
            ])->save();

            Traceability::recordEvent($batch, 'Batch Masuk Shipment', $user, [
                'status' => HarvestBatchStatus::InDistribution->value,
                'shipment_code' => $shipment->code,
                'destination_type' => $shipment->destination_type,
            ]);
        }

        return ApiResponse::created([
            'code' => $shipment->code,
            'status' => $shipment->status,
            'shipment' => ContractFormatter::shipment($shipment->load('sources')),
        ], 'Shipment berhasil dibuat');
    }

    public function sendShipment(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        $shipment = CollectorShipment::query()
            ->with('sources.batch')
            ->where('collector_user_id', $user->id)
            ->where('code', $code)
            ->firstOrFail();

        if ($shipment->status !== ShipmentStatus::ReadyToShip->value) {
            return ApiResponse::error('Shipment hanya bisa dikirim saat status readyToShip', 422);
        }

        $shipment->forceFill([
            'status' => ShipmentStatus::Sent->value,
            'sent_at' => now(),
        ])->save();

        foreach ($shipment->sources as $source) {
            if ($source->batch) {
                Traceability::recordEvent($source->batch, 'Shipment Dikirim', $user, [
                    'status' => HarvestBatchStatus::InDistribution->value,
                    'shipment_code' => $shipment->code,
                ]);
            }
        }

        return ApiResponse::success([
            'code' => $shipment->code,
            'status' => $shipment->status,
            'sent_at' => $shipment->sent_at?->toISOString(),
            'shipment' => ContractFormatter::shipment($shipment->load('sources')),
        ], 'Shipment terkirim');
    }

    public function completeShipment(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        $shipment = CollectorShipment::query()
            ->with('sources.batch')
            ->where('collector_user_id', $user->id)
            ->where('code', $code)
            ->firstOrFail();

        if ($shipment->status !== ShipmentStatus::Sent->value) {
            return ApiResponse::error('Shipment hanya bisa diselesaikan saat status sent', 422);
        }

        $shipment->forceFill([
            'status' => ShipmentStatus::Completed->value,
            'completed_at' => now(),
        ])->save();

        foreach ($shipment->sources as $source) {
            if ($source->batch) {
                $nextStatus = HarvestBatchStatus::InDistribution->value;

                $source->batch->forceFill([
                    'status' => $nextStatus,
                ])->save();

                Traceability::recordEvent($source->batch, 'Shipment Selesai', $user, [
                    'status' => $nextStatus,
                    'shipment_code' => $shipment->code,
                ]);
            }
        }

        return ApiResponse::success([
            'code' => $shipment->code,
            'status' => $shipment->status,
            'completed_at' => $shipment->completed_at?->toISOString(),
            'shipment' => ContractFormatter::shipment($shipment->load('sources')),
        ], 'Shipment selesai');
    }

    public function verifyBatch(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        $batch = HarvestBatch::query()
            ->where('code', $code)
            ->firstOrFail();
        abort_unless($this->isCollectorBatchAccessible($batch, $user), 404);

        if ($batch->status !== HarvestBatchStatus::Created->value) {
            return ApiResponse::error('Batch hanya bisa diverifikasi saat status created', 422);
        }

        $data = $request->validate([
            'received_quantity_kg' => ['required', 'numeric', 'min:0'],
            'received_fruit_count' => ['required', 'integer', 'min:0'],
            'grade_breakdown' => ['nullable', 'array', 'min:1'],
            'grade_breakdown.*.grade' => ['required_with:grade_breakdown', 'string', 'max:50'],
            'grade_breakdown.*.weight_kg' => ['required_with:grade_breakdown', 'numeric', 'min:0'],
            'grade_breakdown.*.fruit_count' => ['required_with:grade_breakdown', 'integer', 'min:0'],
            'quality_notes' => ['nullable', 'string'],
            'verified_by' => ['nullable', 'string', 'max:150'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('owner_user_id', $user->id)->where('owner_role', UserRole::Pengepul->value)],
            'verification_photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ]);

        $breakdowns = collect($data['grade_breakdown'] ?? []);
        $weightTotal = (float) $breakdowns->sum('weight_kg');
        $fruitTotal = (int) $breakdowns->sum('fruit_count');
        $receivedWeight = (float) $data['received_quantity_kg'];
        $receivedFruit = (int) $data['received_fruit_count'];

        if ($breakdowns->isNotEmpty() && abs($weightTotal - $receivedWeight) > 0.01) {
            return ApiResponse::validation([
                'grade_breakdown' => ['Total weight grade breakdown harus sama dengan received_quantity_kg.'],
            ]);
        }

        if ($breakdowns->isNotEmpty() && $fruitTotal !== $receivedFruit) {
            return ApiResponse::validation([
                'grade_breakdown' => ['Total fruit count grade breakdown harus sama dengan received_fruit_count.'],
            ]);
        }

        DB::transaction(function () use ($batch, $data, $user, $request): void {
            $batch->forceFill([
                'status' => HarvestBatchStatus::VerifiedByCollector->value,
                'received_quantity_kg' => $data['received_quantity_kg'],
                'received_fruit_count' => $data['received_fruit_count'],
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'quality_notes' => $data['quality_notes'] ?? null,
                'verification_photo_path' => $request->file('verification_photo')
                    ?->store('uploads/verification', 'public')
                    ?? $batch->verification_photo_path,
                'verified_by_user_id' => $user->id,
                'verified_at' => now(),
            ])->save();

            $batch->gradeBreakdowns()->delete();

            foreach ($data['grade_breakdown'] ?? [] as $breakdown) {
                HarvestBatchGradeBreakdown::create([
                    'harvest_batch_id' => $batch->id,
                    'grade_label' => $breakdown['grade'],
                    'weight_kg' => $breakdown['weight_kg'],
                    'fruit_count' => $breakdown['fruit_count'],
                ]);
            }

            Traceability::recordEvent($batch, 'Batch Diverifikasi Pengepul', $user, [
                'status' => HarvestBatchStatus::VerifiedByCollector->value,
                'verified_by' => $data['verified_by'] ?? $user->full_name,
            ]);
        });

        return ApiResponse::success([
            'code' => $batch->code,
            'status' => HarvestBatchStatus::VerifiedByCollector->value,
            'batch' => ContractFormatter::batch($batch->load('gradeBreakdowns')),
        ], 'Batch terverifikasi');
    }

    public function rejectBatch(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Pengepul->value);

        $batch = HarvestBatch::query()
            ->where('code', $code)
            ->firstOrFail();
        abort_unless($this->isCollectorBatchAccessible($batch, $user), 404);

        if ($batch->status !== HarvestBatchStatus::Created->value) {
            return ApiResponse::error('Batch hanya bisa ditolak saat status created', 422);
        }

        $data = $request->validate([
            'reason' => ['required', 'string'],
            'rejected_by' => ['nullable', 'string', 'max:150'],
        ]);

        $batch->forceFill([
            'status' => HarvestBatchStatus::Rejected->value,
            'rejection_reason' => $data['reason'],
            'rejected_by_user_id' => $user->id,
            'rejected_at' => now(),
        ])->save();

        Traceability::recordEvent($batch, 'Batch Ditolak Pengepul', $user, [
            'status' => HarvestBatchStatus::Rejected->value,
            'reason' => $data['reason'],
        ]);

        return ApiResponse::success([
            'code' => $batch->code,
            'status' => $batch->status,
            'batch' => ContractFormatter::batch($batch),
        ], 'Batch ditolak');
    }

    private function ensureRole(?User $user, string $role): void
    {
        abort_unless($user && $user->role === $role, 403, 'Forbidden');
    }

    private function isCollectorBatchAccessible(HarvestBatch $batch, User $user): bool
    {
        return $batch->recipient_user_id !== null
            && (int) $batch->recipient_user_id === $user->id
            && in_array($batch->recipient_role, ['collector', UserRole::Pengepul->value], true);
    }

    private function validateWarehouse(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'location' => ['required', 'string'],
            'note' => ['nullable', 'string'],
            'is_default' => ['nullable', 'boolean'],
        ]);
    }

    private function warehousePayload(Warehouse $warehouse): array
    {
        return [
            'id' => (string) $warehouse->id,
            'name' => $warehouse->name,
            'location' => $warehouse->location,
            'note' => $warehouse->note,
            'isDefault' => $warehouse->is_default,
            'createdAt' => $warehouse->created_at?->toISOString(),
        ];
    }
}
