<?php

namespace App\Http\Controllers\Api;

use App\Enums\HarvestBatchStatus;
use App\Http\Controllers\Controller;
use App\Models\CollectorShipment;
use App\Models\CollectorShipmentSource;
use App\Models\DistributorSale;
use App\Models\HarvestBatch;
use App\Models\HarvestBatchEvent;
use App\Models\UmkmProductSource;
use App\Support\ApiResponse;
use App\Support\ContractFormatter;
use Illuminate\Http\JsonResponse;

class PublicTraceController extends Controller
{
    public function show(string $batchCode): JsonResponse
    {
        $batch = HarvestBatch::query()
            ->with([
                'farm',
                'farmer',
                'recipient',
                'events.actor.farmerProfile',
                'events.actor.collectorProfile',
                'events.actor.distributorProfile',
                'events.actor.umkmProfile',
                'warehouse',
                'gradeBreakdowns',
            ])
            ->where('code', $batchCode)
            ->first();

        if ($batch === null) {
            $shipment = CollectorShipment::query()
                ->with('sources.batch.farm', 'sources.batch.farmer', 'sources.batch.recipient', 'sources.batch.warehouse', 'sources.batch.events.actor.farmerProfile', 'sources.batch.events.actor.collectorProfile', 'sources.batch.events.actor.distributorProfile', 'sources.batch.events.actor.umkmProfile')
                ->where('code', $batchCode)
                ->first();
            $batch = $shipment?->sources?->first()?->batch;
        }

        if ($batch === null) {
            $sale = DistributorSale::query()
                ->with('batch.farm', 'batch.farmer', 'batch.recipient', 'batch.warehouse', 'batch.events.actor.farmerProfile', 'batch.events.actor.collectorProfile', 'batch.events.actor.distributorProfile', 'batch.events.actor.umkmProfile')
                ->where('code', $batchCode)
                ->first();
            $batch = $sale?->batch;
        }

        abort_if($batch === null, 404, 'Batch tidak ditemukan');

        if ($batch->recipient_user_id !== null
            && in_array($batch->status, [
                HarvestBatchStatus::Created->value,
                HarvestBatchStatus::Rejected->value,
            ], true)) {
            abort(404);
        }

        $shipmentHistory = CollectorShipmentSource::query()
            ->with(['shipment.collector', 'batch'])
            ->where('harvest_batch_id', $batch->id)
            ->get()
            ->groupBy(fn (CollectorShipmentSource $source): string => (string) ($source->shipment?->code ?? $source->collector_shipment_id))
            ->map(function ($sources): array {
                /** @var CollectorShipmentSource $firstSource */
                $firstSource = $sources->first();
                $shipment = $firstSource?->shipment;
                $photoSource = $sources->first(
                    fn (CollectorShipmentSource $source): bool => filled(
                        $source->batch?->verification_photo_path
                    )
                ) ?? $firstSource;

                return [
                    'code' => $shipment?->code,
                    'status' => $shipment?->status,
                    'collector_name' => $shipment?->collector?->full_name,
                    'destination_type' => $shipment?->destination_type,
                    'destination_name' => $shipment?->destination_name,
                    'destination_location' => $shipment?->destination_location,
                    'warehouse_note' => $shipment?->warehouse_note,
                    'source_batch_codes' => $sources
                        ->pluck('source_code_snapshot')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'total_weight_kg' => $shipment?->total_weight_kg === null ? null : (float) $shipment->total_weight_kg,
                    'total_fruit_count' => $shipment?->total_fruit_count,
                    'photo_url' => ContractFormatter::imageUrl(
                        $photoSource?->batch?->verification_photo_path
                    ),
                    'packaged_at' => $shipment?->packaged_at?->toISOString(),
                    'sent_at' => $shipment?->sent_at?->toISOString(),
                    'completed_at' => $shipment?->completed_at?->toISOString(),
                ];
            })
            ->values()
            ->all();

        $publicUrl = url('/api/trace/' . $batch->code);

        $trace = ContractFormatter::trace($batch, $publicUrl);

        $trace['batch'] = ContractFormatter::batch($batch);
        unset($trace['batch']['recipient_user_id'], $trace['batch']['recipient_role']);
        $trace['events'] = $batch->events
            ->sortBy('event_at')
            ->values()
            ->map(function (HarvestBatchEvent $event) use ($batch): array {
                return [
                    ...ContractFormatter::batchEvent($event),
                    'location_label' => $this->eventLocation($event, $batch),
                ];
            })
            ->all();
        $hasUmkmReceiptEvent = collect($trace['events'])->contains(
            function (array $event): bool {
                $title = strtolower((string) ($event['title'] ?? ''));

                return str_contains($title, 'diterima umkm')
                    || str_contains($title, 'penjualan distributor diterima')
                    || str_contains($title, 'shipment diterima');
            }
        );
        if ($batch->status === HarvestBatchStatus::ReceivedByUmkm->value
            && ! $hasUmkmReceiptEvent
            && $batch->verified_at !== null) {
            $trace['events'][] = [
                'title' => 'Batch Diterima UMKM',
                'actor_label' => 'UMKM - ' . ($batch->recipient?->full_name ?? 'Penerima UMKM'),
                'event_at' => $batch->verified_at->toISOString(),
                'description' => 'Batch diterima dan divalidasi oleh UMKM.',
                'location_label' => $this->eventLocation(null, $batch),
                'metadata' => [
                    'status' => HarvestBatchStatus::ReceivedByUmkm->value,
                ],
            ];
            usort(
                $trace['events'],
                fn (array $left, array $right): int => strcmp(
                    (string) ($left['event_at'] ?? ''),
                    (string) ($right['event_at'] ?? '')
                )
            );
        }
        $trace['source_batches'] = [];
        $trace['downstream_products'] = $this->downstreamProducts($batch->id);
        $trace['shipment_history'] = $shipmentHistory;
        $trace['handover_history'] = DistributorSale::query()
            ->with(['seller', 'recipient'])
            ->where('harvest_batch_id', $batch->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (DistributorSale $sale): array => [
                'code' => $sale->code,
                'status' => $sale->status,
                'seller_name' => $sale->seller?->full_name,
                'recipient_name' => $sale->recipient?->full_name,
                'recipient_role' => $sale->recipient_role,
                'expected_weight_kg' => (float) $sale->expected_weight_kg,
                'expected_fruit_count' => $sale->expected_fruit_count,
                'received_weight_kg' => $sale->received_weight_kg === null ? null : (float) $sale->received_weight_kg,
                'received_fruit_count' => $sale->received_fruit_count,
                'created_at' => $sale->created_at?->toISOString(),
                'verified_at' => $sale->verified_at?->toISOString(),
            ])
            ->values()
            ->all();

        return ApiResponse::success($trace);
    }

    private function eventLocation(?HarvestBatchEvent $event, HarvestBatch $batch): string
    {
        $actor = $event?->actor;
        $role = $actor?->role;

        if ($role === 'petani' || $actor === null) {
            $farmLocation = $this->formatLocation($batch->farm?->address, [
                $batch->farm?->village,
                $batch->farm?->district,
                $batch->farm?->city,
                $batch->farm?->province,
            ]) ?: 'Lokasi kebun belum dicatat';

            return 'Kebun ' . ($batch->farm?->name ?? $batch->farm_name_snapshot) . ' - ' . $farmLocation;
        }

        $profile = match ($role) {
            'pengepul' => $actor->collectorProfile,
            'distributor' => $actor->distributorProfile,
            'umkm' => $actor->umkmProfile,
            default => null,
        };

        $profileLocation = $this->formatLocation($profile?->address, [
            $profile?->village,
            $profile?->district,
            $profile?->city,
            $profile?->province,
        ]) ?: 'Lokasi belum dicatat';

        if ($batch->warehouse !== null && in_array($role, ['pengepul', 'distributor'], true)) {
            $warehouseLocation = $batch->warehouse->location ?: $profileLocation;

            return 'Gudang ' . $batch->warehouse->name . ' - ' . $warehouseLocation;
        }

        return $profileLocation;
    }

    private function formatLocation(?string $address, array $parts): string
    {
        $normalizedAddress = filled($address) ? trim($address) : null;
        $values = $normalizedAddress === null ? [] : [$normalizedAddress];
        foreach ($parts as $part) {
            $part = is_string($part) ? trim($part) : '';
            if ($part === '' || ($normalizedAddress !== null && str_contains(strtolower($normalizedAddress), strtolower($part)))) {
                continue;
            }
            $values[] = $part;
        }

        return implode(', ', array_unique($values));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function downstreamProducts(int $batchId): array
    {
        return UmkmProductSource::query()
            ->with('product')
            ->where('harvest_batch_id', $batchId)
            ->latest()
            ->get()
            ->groupBy('umkm_product_id')
            ->map(function ($sources): array {
                /** @var UmkmProductSource $firstSource */
                $firstSource = $sources->first();
                $product = $firstSource?->product;

                return [
                    'code' => $product?->code,
                    'name' => $product?->name,
                    'status' => $product?->status,
                    'category' => $product?->category,
                    'price_label' => ContractFormatter::moneyLabel($product?->price ?? 0),
                    'stock_label' => 'Stok ' . (int) ($product?->stock_qty ?? 0) . ' paket',
                    'source_batch_codes' => $sources
                        ->pluck('source_code_snapshot')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }
}
