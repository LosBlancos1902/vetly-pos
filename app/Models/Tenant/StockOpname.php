<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockOpname extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_COUNTING = 'counting';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $guarded = [];
    protected $casts = [
        'opname_date' => 'date',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class, 'opname_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Build a frozen-context map untuk warehouse tertentu:
     *   [ product_id => opname_id ]
     *
     * Dipanggil di CashierController dan ServiceBundleService untuk
     * mendeteksi mana produk yang sedang "dibekukan" oleh SO aktif.
     *
     * Status considered active: draft, counting. (completed/cancelled tidak.)
     *
     * Single query, indexed by (warehouse_id, status) + (opname_id, product_id).
     */
    public static function frozenContextFor(int $warehouseId): array
    {
        return \DB::table('stock_opname_items')
            ->join('stock_opnames', 'stock_opnames.id', '=', 'stock_opname_items.opname_id')
            ->where('stock_opnames.warehouse_id', $warehouseId)
            ->whereIn('stock_opnames.status', [self::STATUS_DRAFT, self::STATUS_COUNTING])
            ->pluck('stock_opnames.id', 'stock_opname_items.product_id')
            ->toArray();
    }

    /**
     * Apakah ada SO non-terminal untuk warehouse ini? Dipakai concurrency guard
     * di create — max 1 SO aktif per warehouse.
     */
    public static function activeOpnameIdFor(int $warehouseId): ?int
    {
        return self::query()
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', [self::STATUS_DRAFT, self::STATUS_COUNTING])
            ->value('id');
    }
}
