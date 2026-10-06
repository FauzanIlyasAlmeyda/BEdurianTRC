<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DistributorSale extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'seller_user_id',
        'recipient_user_id',
        'recipient_role',
        'harvest_batch_id',
        'warehouse_id',
        'expected_weight_kg',
        'expected_fruit_count',
        'received_weight_kg',
        'received_fruit_count',
        'condition',
        'quality_note',
        'discrepancy_note',
        'rejection_note',
        'status',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'expected_weight_kg' => 'decimal:2',
            'received_weight_kg' => 'decimal:2',
            'verified_at' => 'datetime',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HarvestBatch::class, 'harvest_batch_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
