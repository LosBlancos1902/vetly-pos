<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    use \Spatie\Activitylog\Traits\LogsActivity;
    use \App\Models\Tenant\Concerns\LogsTenantActivity;

    public const ACTIVITY_LOG_NAME = 'accounting';

    protected $guarded = [];

    protected $casts = [
        'date' => 'date',
        'posted_at' => 'datetime',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
}
