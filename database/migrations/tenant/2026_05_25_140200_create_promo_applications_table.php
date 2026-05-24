<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_applications', function (Blueprint $table) {
            $table->id();
            // Promo nullable supaya histori sale tetap valid kalau owner
            // hapus promo (cuma audit hilang nama promo, sale tidak rusak).
            $table->foreignId('promo_id')->nullable()
                ->constrained('promos')->nullOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->decimal('discount_amount', 15, 2);
            // Snapshot COA code yg dipakai (kalau promo dihapus, masih bisa trace
            // dari sini).
            $table->string('coa_code', 32)->nullable();
            $table->timestamp('applied_at')->useCurrent();

            $table->index('promo_id');
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_applications');
    }
};
