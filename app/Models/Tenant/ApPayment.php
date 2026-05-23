<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApPayment extends Model
{
    protected $guarded = [];
    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
    ];

    public function accountsPayable(): BelongsTo
    {
        return $this->belongsTo(AccountsPayable::class, 'ap_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }
}
