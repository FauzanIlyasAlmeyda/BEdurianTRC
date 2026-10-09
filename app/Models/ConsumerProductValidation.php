<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsumerProductValidation extends Model
{
    protected $fillable = [
        'umkm_product_id',
        'consumer_user_id',
        'validated_at',
    ];

    protected function casts(): array
    {
        return ['validated_at' => 'datetime'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(UmkmProduct::class, 'umkm_product_id');
    }

    public function consumer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consumer_user_id');
    }
}
