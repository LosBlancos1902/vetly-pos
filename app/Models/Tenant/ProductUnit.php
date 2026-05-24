<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ProductUnit extends Model
{
    protected $table = 'product_units';
    protected $guarded = [];

    protected $casts = [
        'level' => 'integer',
        'conversion_to_base' => 'decimal:4',
        'price' => 'decimal:2',
        'is_purchase_unit' => 'boolean',
        'is_sale_unit' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(MasterUnit::class, 'unit_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductUnitPrice::class);
    }

    public function isBase(): bool
    {
        return $this->level === 1;
    }

    /**
     * SINGLE SOURCE OF TRUTH untuk resolve harga jual.
     *
     * Aturan fallback (urut, stop di hit pertama):
     *   1. product_unit_prices(unit=this, tier=tierId) → return.
     *   2. tier default (is_default=true) untuk satuan yg SAMA → return.
     *      (Jangan scale antar-satuan supaya predictable.)
     *   3. Legacy fallback: product_units.price kalau ada, else
     *      products.price × conversion_to_base. Jaga produk lama
     *      tetap punya harga kalau backfill miss / edge case.
     *
     * Default tier id di-cache per-request via array cache (CACHE_STORE=array)
     * supaya 1 lookup table per request, bukan per call.
     */
    public function priceForTier(int $tierId): float
    {
        // Step 1: exact tier match.
        $prices = $this->relationLoaded('prices') ? $this->prices : null;
        if ($prices) {
            $hit = $prices->firstWhere('price_tier_id', $tierId);
            if ($hit !== null) {
                return (float) $hit->price;
            }
        } else {
            $hit = ProductUnitPrice::where('product_unit_id', $this->id)
                ->where('price_tier_id', $tierId)
                ->value('price');
            if ($hit !== null) {
                return (float) $hit;
            }
        }

        // Step 2: fallback ke tier default (untuk satuan ini). Hindari
        // rekursi infinite kalau tierId sudah = default.
        $defaultTierId = static::defaultTierId();
        if ($defaultTierId !== null && $defaultTierId !== $tierId) {
            if ($prices) {
                $hit = $prices->firstWhere('price_tier_id', $defaultTierId);
                if ($hit !== null) {
                    return (float) $hit->price;
                }
            } else {
                $hit = ProductUnitPrice::where('product_unit_id', $this->id)
                    ->where('price_tier_id', $defaultTierId)
                    ->value('price');
                if ($hit !== null) {
                    return (float) $hit;
                }
            }
        }

        // Step 3: legacy fallback — kolom price lama atau products.price
        // × conversion. Safety net untuk produk pre-tier yang belum
        // tersentuh backfill.
        if ($this->price !== null) {
            return (float) $this->price;
        }

        $productPrice = $this->product?->price ?? DB::table('products')
            ->where('id', $this->product_id)
            ->value('price');

        return (float) $productPrice * (float) $this->conversion_to_base;
    }

    /**
     * Lookup id tier default (is_default=true). Cache per-request supaya
     * tidak query berulang. Reset via `Cache::driver('array')->forget()`
     * setelah update price_tiers di test.
     */
    public static function defaultTierId(): ?int
    {
        return Cache::driver('array')->rememberForever(
            'price_tier:default_id',
            fn () => DB::table('price_tiers')
                ->where('is_default', true)
                ->value('id'),
        );
    }
}
