<?php

namespace App\Models\Tenant;

use App\Models\Tenant\Concerns\ScopedToWarehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use ScopedToWarehouse;

    protected $table = 'stock_movements';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'qty' => 'decimal:4',
        'qty_input' => 'decimal:4',
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

    public function unitInput(): BelongsTo
    {
        return $this->belongsTo(MasterUnit::class, 'unit_id_input');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
