<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promo extends Model
{
    protected $guarded = [];

    protected $casts = [
        'discount_value' => 'decimal:4',
        'max_discount_amount' => 'decimal:2',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'days_of_week' => 'array',
        'min_purchase' => 'decimal:2',
        'min_qty' => 'integer',
        'quota_total' => 'integer',
        'quota_used' => 'integer',
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public const TYPE_PERIODE = 'periode_discount';
    public const TYPE_PER_ITEM = 'per_item';
    public const TYPE_VOUCHER = 'voucher';
    public const TYPE_BUNDLING = 'bundling';
    public const TYPE_TEBUS_MURAH = 'tebus_murah';

    public function discountCoa(): BelongsTo
    {
        return $this->belongsTo(Coa::class, 'discount_coa_id');
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'promo_warehouse')
            ->withTimestamps();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(PromoApplication::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Apakah masih ada slot kuota? null=unlimited → always true.
     */
    public function hasQuotaLeft(): bool
    {
        if ($this->quota_total === null) {
            return true;
        }

        return $this->quota_used < $this->quota_total;
    }

    /**
     * COA code yg dipakai utk journal entry. Fallback ke 4199 contra-revenue
     * existing kalau owner tidak set spesifik.
     */
    public function effectiveCoaCode(): string
    {
        return $this->discountCoa?->code ?? '4199';
    }
}
