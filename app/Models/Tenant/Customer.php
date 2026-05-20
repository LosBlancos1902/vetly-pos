<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'birthday' => 'date',
        'total_spent' => 'decimal:2',
        'points' => 'integer',
    ];
}
