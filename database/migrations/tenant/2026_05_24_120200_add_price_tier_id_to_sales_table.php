<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Audit trail: tier yg dipakai kasir saat sale dibuat.
            // Nullable supaya sale lama (pre-tier) tetap valid.
            // Tidak di-cascade — kalau tier dihapus, sales record tetap ada
            // sebagai histori (nullOnDelete).
            $table->foreignId('price_tier_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('price_tiers')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['price_tier_id']);
            $table->dropColumn('price_tier_id');
        });
    }
};
