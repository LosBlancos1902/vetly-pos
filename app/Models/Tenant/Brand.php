<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];
}
