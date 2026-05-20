<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class MasterUnit extends Model
{
    protected $table = 'master_units';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['is_base' => 'boolean'];
}
