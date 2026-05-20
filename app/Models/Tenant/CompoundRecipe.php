<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompoundRecipe extends Model
{
    protected $guarded = [];

    protected $casts = [
        'yield_qty' => 'decimal:4',
        'racik_fee' => 'decimal:2',
        'markup_percent' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function yieldUnit(): BelongsTo
    {
        return $this->belongsTo(MasterUnit::class, 'yield_unit_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(CompoundRecipeComponent::class, 'recipe_id');
    }
}
