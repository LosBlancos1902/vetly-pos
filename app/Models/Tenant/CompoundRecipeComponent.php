<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompoundRecipeComponent extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty' => 'decimal:4',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(CompoundRecipe::class, 'recipe_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'component_product_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MasterUnit::class, 'unit_id');
    }
}
