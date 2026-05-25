<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_warehouse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promo_id')->constrained('promos')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['promo_id', 'warehouse_id']);
            // Pivot KOSONG (no row utk promo X) = berlaku semua cabang
            // ([Semua Cabang] di Accurate). Resolver: kalau promo punya
            // 0 pivot rows → applicable di warehouse manapun.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_warehouse');
    }
};
