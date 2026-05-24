<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $guarded = [];

    protected $casts = [
        'birthday' => 'date',
        'total_spent' => 'decimal:2',
        'points' => 'integer',
        'is_active' => 'boolean',
    ];

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function category(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CustomerCategory::class, 'customer_category_id');
    }

    /**
     * Generate unique customer code: CUS-YYYYMMDD-NNNN.
     * NNNN = nomor urut hari itu (4 digit, padded).
     */
    public static function generateCode(): string
    {
        $prefix = 'CUS-'.now()->format('Ymd').'-';
        $lastSeq = static::where('code', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->value('code');

        $next = $lastSeq ? ((int) substr($lastSeq, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
