<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding Struk — tenant-level (1 row per tenant DB).
 *
 * Tenant DB sudah ter-isolasi per tenant; tabel ini cuma menyimpan 1 row
 * (singleton). Logo disimpan sbg base64 data URI supaya tdk butuh
 * storage:link + tenant-aware filesystem path (thermal logo umumnya
 * ≤200x80px → kecil di DB, render langsung di <img src="data:...">).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_settings', function (Blueprint $table) {
            $table->id();
            $table->string('brand_name')->nullable();
            $table->longText('logo_data')->nullable();   // base64 data URI (data:image/png;base64,...)
            $table->string('logo_mime', 50)->nullable(); // mis. image/png, image/jpeg
            $table->text('footer_text')->nullable();
            $table->string('npwp', 50)->nullable();
            $table->string('license_no', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_settings');
    }
};
