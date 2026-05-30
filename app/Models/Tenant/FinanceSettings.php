<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton per tenant DB (1 row). Pola sama BrandingSettings.
 * Menyimpan threshold approval + (future) tanggal kunci tutup buku.
 */
class FinanceSettings extends Model
{
    use \Spatie\Activitylog\Traits\LogsActivity;
    use \App\Models\Tenant\Concerns\LogsTenantActivity;

    protected $table = 'finance_settings';

    public const ACTIVITY_LOG_NAME = 'settings';

    protected $guarded = [];

    protected $casts = [
        'approval_threshold' => 'decimal:2',
        'effective_date_locked_before' => 'date',
        'expense_presets' => 'array',
    ];

    public static function singleton(): self
    {
        // Set default eksplisit supaya attribute ter-load di memori (firstOrCreate
        // tidak mengambil DB default ke instance baru).
        return static::query()->firstOrCreate(['id' => 1], [
            'approval_threshold' => 5000000,
        ]);
    }
}
