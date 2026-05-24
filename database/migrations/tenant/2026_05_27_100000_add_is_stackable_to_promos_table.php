<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            // Default FALSE = eksklusif. Promo existing (pre-fix) otomatis
            // jadi eksklusif setelah migrate — behavior stack-all-semua stop.
            // Owner edit manual kalau mau set TRUE (stackable).
            //
            // Resolver logic baru:
            //   - is_stackable=false → masuk pool eksklusif, dipilih SATU
            //     yg diskonnya terbesar (tie-break ID terkecil)
            //   - is_stackable=true → numpuk di atas eksklusif terpilih
            $table->boolean('is_stackable')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->dropColumn('is_stackable');
        });
    }
};
