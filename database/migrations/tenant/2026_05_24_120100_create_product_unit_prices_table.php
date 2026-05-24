<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_unit_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_unit_id')->constrained('product_units')->cascadeOnDelete();
            $table->foreignId('price_tier_id')->constrained('price_tiers')->cascadeOnDelete();
            $table->decimal('price', 15, 2);
            $table->timestamps();

            $table->unique(['product_unit_id', 'price_tier_id']);
        });

        // ── BACKFILL ──────────────────────────────────────────────────────
        // Salin harga legacy ke tier default supaya produk existing TIDAK
        // jadi 0/kosong setelah migrate. Source per row:
        //   1. product_units.price (kolom legacy per pivot) kalau ada
        //   2. products.price × conversion_to_base (fallback skenario lama
        //      di mana harga cuma di products.price tanpa per-unit)
        //
        // Tenant baru: product_units kosong → loop no-op (clean).
        // Tenant existing (mis. demo): semua product_units kebaca + kebackfill.
        $defaultTierId = DB::table('price_tiers')
            ->where('is_default', true)
            ->value('id');

        if ($defaultTierId === null) {
            return; // safety; migration price_tiers gagal? skip backfill.
        }

        $rows = DB::table('product_units as pu')
            ->join('products as p', 'p.id', '=', 'pu.product_id')
            ->select(
                'pu.id as product_unit_id',
                'pu.price as unit_price',
                'p.price as product_price',
                'pu.conversion_to_base',
            )
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $now = now();
        $insert = [];
        foreach ($rows as $r) {
            $price = $r->unit_price !== null
                ? (float) $r->unit_price
                : (float) $r->product_price * (float) $r->conversion_to_base;

            $insert[] = [
                'product_unit_id' => $r->product_unit_id,
                'price_tier_id' => $defaultTierId,
                'price' => $price,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Chunk untuk safety di tenant besar.
        foreach (array_chunk($insert, 500) as $batch) {
            DB::table('product_unit_prices')->insert($batch);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_unit_prices');
    }
};
