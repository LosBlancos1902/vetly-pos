<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $guarded = [];

    public const TYPE_SALEABLE_RETAIL = 'saleable_retail';
    public const TYPE_COMPOUNDABLE_DRUG = 'compoundable_drug';
    public const TYPE_SERVICE = 'service';
    public const TYPE_SERVICE_WITH_CONSUMPTION = 'service_with_consumption';
    public const TYPE_RAW_MATERIAL = 'raw_material';

    protected $casts = [
        'cost_avg' => 'decimal:2',
        'price' => 'decimal:2',
        'min_stock' => 'decimal:4',
        'max_stock' => 'decimal:4',
        'is_active' => 'boolean',
        'is_sellable_directly' => 'boolean',
        'requires_prescription' => 'boolean',
        'has_expiry' => 'boolean',
        'has_batch' => 'boolean',
        'allow_stock_minus' => 'boolean',
    ];

    public function isService(): bool
    {
        return in_array($this->type, [self::TYPE_SERVICE, self::TYPE_SERVICE_WITH_CONSUMPTION], true);
    }

    public function isCompoundable(): bool
    {
        return $this->type === self::TYPE_COMPOUNDABLE_DRUG;
    }

    public function isRawMaterial(): bool
    {
        return $this->type === self::TYPE_RAW_MATERIAL;
    }

    public function tracksInventory(): bool
    {
        return $this->type !== self::TYPE_SERVICE;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(MasterUnit::class, 'base_unit_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }
}
