<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            // Hierarki support (mirror Category produk) — Member > VIP, dst.
            $table->foreignId('parent_id')->nullable()
                ->constrained('customer_categories')->nullOnDelete();
            // Color: shadcn badge variant slug (muted/info/success/destructive/warning/secondary).
            // Validasi di controller, supaya frontend langsung pakai Badge variant
            // tanpa custom styling.
            $table->string('color', 32)->default('muted');
            // Icon: 1-3 char (emoji atau abbrev). Optional, untuk visual cue
            // di list + POS picker.
            $table->string('icon', 8)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('name');
            $table->index('parent_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_categories');
    }
};
