<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

/**
 * Singleton per tenant DB (1 row). Disimpan apa adanya — branding ke depan
 * (tidak men-snapshot ke transaksi lama; struk lama tetap render dgn branding
 * terbaru saat dilihat — keputusan: simplicity > historical accuracy).
 */
class BrandingSettings extends Model
{
    protected $table = 'branding_settings';

    protected $guarded = [];

    /**
     * Ambil / buat row singleton.
     */
    public static function singleton(): self
    {
        return static::query()->firstOrCreate(['id' => 1]);
    }
}
