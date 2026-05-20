<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    protected $table = 'stock_movements';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'qty' => 'decimal:4',
        'cost' => 'decimal:2',
        'balance_qty_after' => 'decimal:4',
        'balance_cost_after' => 'decimal:2',
        'created_at' => 'datetime',
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
