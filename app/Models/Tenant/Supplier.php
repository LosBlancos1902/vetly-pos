<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    // TODO fase P2 (Purchase Order): wire HasMany ke PurchaseOrder.
    // public function purchaseOrders(): HasMany
    // {
    //     return $this->hasMany(PurchaseOrder::class);
    // }
}
