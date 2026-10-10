<?php

namespace App\Http\Controllers\Api;

use App\Enums\HarvestBatchStatus;
use App\Enums\ReceiptCondition;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\DistributorSale;
use App\Models\HarvestBatch;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\ContractFormatter;
use App\Support\Traceability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class DistributorSaleController extends Controller
{
    private const RECIPIENT_ROLES = [
        UserRole::Pengepul->value,
        UserRole::Distributor->value,
        UserRole::Umkm->value,
    ];

    public function inventoryBatches(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Distributor->value);

        $batches = HarvestBatch::query()
            ->with(['farm', 'gradeBreakdowns', 'warehouse'])
            ->where('status', HarvestBatchStatus::ReceivedByDistributor->value)
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', UserRole::Distributor->value)
            ->whereDoesntHave('distributorSales', fn ($query) => $query->where('status', 'initiated'))
            ->latest()
            ->get()
            ->map(function (HarvestBatch $batch): array {
                return ContractFormatter::batch($batch) + [
                    'warehouse_name' => $batch->warehouse?->name,
                ];
            })
            ->values()
            ->all();

        return ApiResponse::success($batches);
    }

    public function recipients(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Distributor->value);

        $request->merge(['search' => trim((string) $request->input('search'))]);
        $data = $request->validate([
            'role' => ['required', Rule::in([
                'collector',
                UserRole::Pengepul->value,
                UserRole::Distributor->value,
                UserRole::Umkm->value,
            ])],
            'search' => ['required', 'string', 'min:1', 'max:100'],
        ]);
        $lookupRole = $data['role'] === 'collector'
            ? UserRole::Pengepul->value
            : $data['role'];
        $search = $data['search'];
        $profileRelation = match ($lookupRole) {
            UserRole::Pengepul->value => 'collectorProfile',
            UserRole::Distributor->value => 'distributorProfile',
            UserRole::Umkm->value => 'umkmProfile',
        };
        $profileNameColumn = $lookupRole === UserRole::Umkm->value
            ? 'name'
            : 'business_name';

        $recipients = User::query()
            ->where('is_active', true)
            ->whereKeyNot($user->id)
            ->where('role', $lookupRole)
            ->where(function ($query) use ($search, $profileRelation, $profileNameColumn): void {
                $query->where('public_account_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhereHas($profileRelation, fn ($profileQuery) => $profileQuery
                        ->where($profileNameColumn, 'like', "%{$search}%"));
                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            })
            ->with(['collectorProfile', 'distributorProfile', 'umkmProfile'])
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(50)
            ->get()
            ->map(function (User $recipient): array {
                $profile = match ($recipient->role) {
                    UserRole::Pengepul->value => $recipient->collectorProfile,
                    UserRole::Distributor->value => $recipient->distributorProfile,
                    UserRole::Umkm->value => $recipient->umkmProfile,
                    default => null,
                };
                $name = match ($recipient->role) {
                    UserRole::Pengepul->value, UserRole::Distributor->value => $profile?->business_name,
                    UserRole::Umkm->value => $profile?->name,
                    default => null,
                };

                return [
                    'id' => $recipient->id,
                    'public_id' => $recipient->public_account_id ?? (string) $recipient->id,
                    'first_name' => $recipient->first_name,
                    'last_name' => $recipient->last_name,
                    'full_name' => filled($name) ? $name : $recipient->full_name,
                    'role' => $recipient->role === UserRole::Pengepul->value
                        ? 'collector'
                        : $recipient->role,
                    'address' => $profile?->address ?? '',
                ];
            })
            ->values()
            ->all();

        return ApiResponse::success($recipients);
    }

    public function sales(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Distributor->value);

        return ApiResponse::success(
            DistributorSale::query()
                ->with(['seller.collectorProfile', 'seller.distributorProfile', 'recipient.collectorProfile', 'recipient.distributorProfile', 'recipient.umkmProfile', 'batch', 'warehouse'])
                ->where('seller_user_id', $user->id)
                ->latest()
                ->get()
                ->map(fn (DistributorSale $sale): array => $this->payload($sale))
                ->values()
                ->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->ensureRole($user, UserRole::Distributor->value);

        $data = $request->validate([
            'batch_code' => ['required', 'string', 'exists:harvest_batches,code'],
            'recipient_user_id' => ['required', 'integer', 'exists:users,id'],
            'recipient_role' => ['required', Rule::in(self::RECIPIENT_ROLES)],
            'quality_note' => ['nullable', 'string'],
        ]);

        $recipient = User::query()
            ->whereKey($data['recipient_user_id'])
            ->where('is_active', true)
            ->where('role', $data['recipient_role'])
            ->whereKeyNot($user->id)
            ->firstOrFail();

        $sale = DB::transaction(function () use ($data, $user, $recipient): DistributorSale {
            $batch = HarvestBatch::query()
                ->where('code', $data['batch_code'])
                ->lockForUpdate()
                ->firstOrFail();

            abort_unless(
                $batch->status === HarvestBatchStatus::ReceivedByDistributor->value
                    && (int) $batch->recipient_user_id === $user->id
                    && $batch->recipient_role === UserRole::Distributor->value,
                422,
                'Batch tidak tersedia pada stok distributor ini.'
            );

            $alreadyInSale = DistributorSale::query()
                ->where('harvest_batch_id', $batch->id)
                ->where('status', 'initiated')
                ->exists();
            abort_if($alreadyInSale, 422, 'Batch sedang menunggu konfirmasi penjualan lain.');

            $sale = DistributorSale::create([
                'code' => 'SALE'.now()->format('Ymd').Str::upper(Str::random(6)),
                'seller_user_id' => $user->id,
                'recipient_user_id' => $recipient->id,
                'recipient_role' => $recipient->role,
                'harvest_batch_id' => $batch->id,
                'warehouse_id' => $batch->warehouse_id,
                'expected_weight_kg' => $batch->received_quantity_kg ?? $batch->quantity_kg,
                'expected_fruit_count' => $batch->received_fruit_count ?? $batch->fruit_count ?? 0,
                'quality_note' => $data['quality_note'] ?? null,
                'status' => 'initiated',
            ]);

            Traceability::recordEvent($batch, 'Penjualan Distributor Menunggu T2', $user, [
                'sale_code' => $sale->code,
                'recipient_role' => $recipient->role,
            ]);

            return $sale;
        });

        return ApiResponse::created(
            $this->payload($sale->load(['seller.collectorProfile', 'seller.distributorProfile', 'recipient.collectorProfile', 'recipient.distributorProfile', 'recipient.umkmProfile', 'batch', 'warehouse'])),
            'T1 penjualan dibuat; menunggu konfirmasi penerima.'
        );
    }

    public function incoming(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, self::RECIPIENT_ROLES, true), 403, 'Forbidden');

        return ApiResponse::success(
            DistributorSale::query()
                ->with(['seller.collectorProfile', 'seller.distributorProfile', 'recipient.collectorProfile', 'recipient.distributorProfile', 'recipient.umkmProfile', 'batch', 'warehouse'])
                ->where('recipient_user_id', $user->id)
                ->where('recipient_role', $user->role)
                ->where('status', 'initiated')
                ->latest()
                ->get()
                ->map(fn (DistributorSale $sale): array => $this->payload($sale))
                ->values()
                ->all()
        );
    }

    public function receive(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, self::RECIPIENT_ROLES, true), 403, 'Forbidden');

        $sale = DistributorSale::query()
            ->with(['batch', 'seller.collectorProfile', 'seller.distributorProfile', 'recipient.collectorProfile', 'recipient.distributorProfile', 'recipient.umkmProfile', 'warehouse'])
            ->where('code', $code)
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', $user->role)
            ->where('status', 'initiated')
            ->firstOrFail();

        $data = $request->validate([
            'received_weight_kg' => ['required', 'numeric', 'min:0'],
            'received_fruit_count' => ['required', 'integer', 'min:0'],
            'condition' => ['required', Rule::in(ReceiptCondition::values())],
            'discrepancy_note' => ['nullable', 'string'],
            'quality_note' => ['nullable', 'string'],
        ]);
        $hasDiscrepancy = abs((float) $data['received_weight_kg'] - (float) $sale->expected_weight_kg) > 0.01
            || (int) $data['received_fruit_count'] !== (int) $sale->expected_fruit_count;

        if ($hasDiscrepancy && blank($data['discrepancy_note'] ?? null)) {
            return ApiResponse::validation([
                'discrepancy_note' => ['Catatan selisih wajib diisi jika kuantitas berbeda.'],
            ]);
        }

        $saleId = $sale->id;
        DB::transaction(function () use ($saleId, $user, $data): void {
            $sale = DistributorSale::query()
                ->with('batch')
                ->whereKey($saleId)
                ->where('recipient_user_id', $user->id)
                ->where('recipient_role', $user->role)
                ->where('status', 'initiated')
                ->lockForUpdate()
                ->firstOrFail();

            $sale->forceFill([
                'status' => 'verified',
                'received_weight_kg' => $data['received_weight_kg'],
                'received_fruit_count' => $data['received_fruit_count'],
                'condition' => $data['condition'],
                'discrepancy_note' => $data['discrepancy_note'] ?? null,
                'quality_note' => $data['quality_note'] ?? $sale->quality_note,
                'verified_at' => now(),
            ])->save();

            $batchStatus = match ($user->role) {
                UserRole::Pengepul->value => HarvestBatchStatus::VerifiedByCollector->value,
                UserRole::Distributor->value => HarvestBatchStatus::ReceivedByDistributor->value,
                UserRole::Umkm->value => HarvestBatchStatus::ReceivedByUmkm->value,
            };
            $sale->batch->forceFill([
                'status' => $batchStatus,
                'recipient_user_id' => $user->id,
                'recipient_role' => $user->role,
                'received_quantity_kg' => $data['received_weight_kg'],
                'received_fruit_count' => $data['received_fruit_count'],
                'verified_by_user_id' => $user->id,
                'verified_at' => now(),
            ])->save();

            Traceability::recordEvent($sale->batch, 'Penjualan Distributor Diterima', $user, [
                'sale_code' => $sale->code,
                'status' => $batchStatus,
                'condition' => $data['condition'],
            ]);
        });

        return ApiResponse::success(
            $this->payload($sale->fresh(['seller.collectorProfile', 'seller.distributorProfile', 'recipient.collectorProfile', 'recipient.distributorProfile', 'recipient.umkmProfile', 'batch', 'warehouse'])),
            'T2 penerimaan berhasil disimpan.'
        );
    }

    public function reject(Request $request, string $code): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && in_array($user->role, self::RECIPIENT_ROLES, true), 403, 'Forbidden');

        $sale = DistributorSale::query()
            ->with(['batch', 'seller.collectorProfile', 'seller.distributorProfile', 'recipient.collectorProfile', 'recipient.distributorProfile', 'recipient.umkmProfile', 'warehouse'])
            ->where('code', $code)
            ->where('recipient_user_id', $user->id)
            ->where('recipient_role', $user->role)
            ->where('status', 'initiated')
            ->firstOrFail();

        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $saleId = $sale->id;
        DB::transaction(function () use ($saleId, $user, $data): void {
            $sale = DistributorSale::query()
                ->with('batch')
                ->whereKey($saleId)
                ->where('recipient_user_id', $user->id)
                ->where('recipient_role', $user->role)
                ->where('status', 'initiated')
                ->lockForUpdate()
                ->firstOrFail();

            $sale->forceFill([
                'status' => 'rejected',
                'rejection_note' => trim($data['reason']),
                'verified_at' => now(),
            ])->save();

            Traceability::recordEvent($sale->batch, 'Penjualan Distributor Ditolak', $user, [
                'sale_code' => $sale->code,
                'reason' => trim($data['reason']),
            ]);
        });

        return ApiResponse::success(
            $this->payload($sale->fresh(['seller.collectorProfile', 'seller.distributorProfile', 'recipient.collectorProfile', 'recipient.distributorProfile', 'recipient.umkmProfile', 'batch', 'warehouse'])),
            'Penjualan ditolak; batch kembali tersedia bagi distributor penjual.'
        );
    }

    private function payload(DistributorSale $sale): array
    {
        return [
            'id' => $sale->code,
            'code' => $sale->code,
            'seller_user_id' => $sale->seller_user_id,
            'seller_name' => $this->accountName($sale->seller),
            'buyer_distributor_id' => (string) $sale->recipient_user_id,
            'buyer_name' => $this->accountName($sale->recipient),
            'buyer_role' => $sale->recipient_role === UserRole::Pengepul->value ? 'collector' : $sale->recipient_role,
            'source_warehouse_id' => $sale->warehouse_id === null ? '' : (string) $sale->warehouse_id,
            'source_warehouse_name' => $sale->warehouse?->name ?? 'Gudang distributor',
            'destination_location' => $sale->recipient?->distributorProfile?->address
                ?? $sale->recipient?->collectorProfile?->address
                ?? $sale->recipient?->umkmProfile?->address
                ?? '',
            'item_code' => $sale->batch?->code,
            'item_name' => 'Durian '.$sale->batch?->variety,
            'expected_weight_kg' => (float) $sale->expected_weight_kg,
            'expected_fruit_count' => (int) $sale->expected_fruit_count,
            'initiated_at' => $sale->created_at?->toISOString(),
            'status' => $sale->status,
            'verified_at' => $sale->verified_at?->toISOString(),
            'received_weight_kg' => $sale->received_weight_kg === null ? null : (float) $sale->received_weight_kg,
            'received_fruit_count' => $sale->received_fruit_count,
            'condition' => $sale->condition,
            'discrepancy_note' => $sale->discrepancy_note,
            'quality_note' => $sale->quality_note,
            'rejection_note' => $sale->rejection_note,
            'batch' => $sale->batch ? ContractFormatter::batch($sale->batch) : null,
        ];
    }

    private function ensureRole(?User $user, string $role): void
    {
        abort_unless($user && $user->role === $role, 403, 'Forbidden');
    }

    private function accountName(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $profileName = match ($user->role) {
            UserRole::Pengepul->value => $user->collectorProfile?->business_name,
            UserRole::Distributor->value => $user->distributorProfile?->business_name,
            UserRole::Umkm->value => $user->umkmProfile?->name,
            default => null,
        };

        return filled($profileName) ? $profileName : $user->full_name;
    }
}
