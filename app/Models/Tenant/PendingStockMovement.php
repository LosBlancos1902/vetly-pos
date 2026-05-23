<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingStockMovement extends Model
{
    protected $guarded = [];
    public $timestamps = false; // hanya created_at
    protected $casts = [
        'qty_base' => 'decimal:4',
        'cost_per_base' => 'decimal:2',
        'applied_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'opname_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function appliedMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'applied_movement_id');
    }
}
