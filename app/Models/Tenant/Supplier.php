<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use \Spatie\Activitylog\Traits\LogsActivity;
    use \App\Models\Tenant\Concerns\LogsTenantActivity;

    public const ACTIVITY_LOG_NAME = 'master';

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    // TODO fase P2 (Purchase Order): wire HasMany ke PurchaseOrder.
    // public function purchaseOrders(): HasMany
    // {
    //     return $this->hasMany(PurchaseOrder::class);
    // }
}
