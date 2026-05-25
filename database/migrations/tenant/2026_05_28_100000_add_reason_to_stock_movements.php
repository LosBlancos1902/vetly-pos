<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kategori penyesuaian persediaan manual. Existing rows + SO-driven adjustments
 * tetap null (SO sudah distinguish via ref_type='App\Models\Tenant\StockOpname').
 * Manual adjustment baru wajib kirim reason (di-validate di controller).
 *
 * Forward-only: legacy adjustment yang sudah ada di prod tetap valid dengan
 * reason=null — engine dan reporting tidak break.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->enum('reason', [
                'rusak', 'hilang', 'expired', 'koreksi',
            ])->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
