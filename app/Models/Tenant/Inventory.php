<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Inventory extends Model
{
    use ScopedToWarehouse;

    protected $table = 'inventories';
    protected $guarded = [];

    protected $casts = [
        'qty' => 'decimal:4',
        'cost_avg' => 'decimal:2',
        'last_movement_at' => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
