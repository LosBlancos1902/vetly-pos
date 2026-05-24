<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PromoApplication extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $casts = [
        'discount_amount' => 'decimal:2',
        'applied_at' => 'datetime',
    ];

    public function promo(): BelongsTo
    {
        return $this->belongsTo(Promo::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }
}
