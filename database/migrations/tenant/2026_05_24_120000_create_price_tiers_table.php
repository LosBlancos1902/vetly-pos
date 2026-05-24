<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            // Anchor untuk fallback. Tepat 1 baris is_default=true per tenant
            // (di-enforce di service layer + custom validation, bukan unique
            // index karena MySQL/MariaDB partial unique tidak portable).
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_default');
        });

        // Seed baseline tier "Eceran" untuk SETIAP tenant baru. Pada migrate
        // tenant existing, ini juga jalan sekali — produk lama akan dipakai
        // sebagai source backfill di migration product_unit_prices.
        DB::table('price_tiers')->insert([
            'name' => 'Eceran',
            'sort_order' => 1,
            'is_default' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('price_tiers');
    }
};
