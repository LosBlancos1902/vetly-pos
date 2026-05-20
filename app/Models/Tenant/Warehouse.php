<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
}
