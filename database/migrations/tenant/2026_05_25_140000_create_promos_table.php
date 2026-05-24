<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promos', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // 5 tipe — fase 1 baru periode_discount yg diimplement penuh,
            // 4 lainnya slot ready dgn strategy stub.
            $table->enum('type', [
                'periode_discount',
                'per_item',
                'voucher',
                'bundling',
                'tebus_murah',
            ]);

            // Nilai diskon
            $table->enum('discount_kind', ['percent', 'nominal']);
            $table->decimal('discount_value', 15, 4);
            $table->decimal('max_discount_amount', 15, 2)->nullable(); // cap utk percent

            // Dimensi 1: PERIODE
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            // Optional spesifik hari (mon..sun) — null = sepanjang periode
            $table->json('days_of_week')->nullable();
            // Optional happy hour — null = sepanjang hari
            $table->time('time_start')->nullable();
            $table->time('time_end')->nullable();

            // Dimensi 3: COA (nullable → fallback 4199 contra-revenue existing).
            // Foreign key constrained ke coa.id — kalau owner hapus COA,
            // promo tidak boleh orphan jadi nullOnDelete.
            $table->foreignId('discount_coa_id')->nullable()
                ->constrained('coa')->nullOnDelete();

            // Dimensi 4: SYARAT
            $table->decimal('min_purchase', 15, 2)->default(0);
            $table->integer('min_qty')->default(0);

            // Dimensi 5: KUOTA
            $table->unsignedInteger('quota_total')->nullable(); // null = unlimited
            $table->unsignedInteger('quota_used')->default(0);

            // Slot extensibility utk tipe nanti (voucher code, bundling rules, dst)
            $table->json('config')->nullable();

            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promos');
    }
};
