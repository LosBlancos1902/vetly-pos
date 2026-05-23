<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    protected $guarded = [];
    protected $casts = [
        'received_at' => 'date',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class, 'gr_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'po_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
