<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding per-cabang: telp + footer override.
 * Address sudah ada (lihat 2026_05_20_100260_create_warehouses_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('address');
            $table->text('footer_override')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['phone', 'footer_override']);
        });
    }
};
