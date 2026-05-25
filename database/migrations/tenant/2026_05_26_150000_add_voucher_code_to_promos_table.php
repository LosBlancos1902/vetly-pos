<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            // Voucher code (tipe 3). Nullable krn cuma dipakai utk
            // type=voucher; tipe lain nullnya saja.
            //
            // Unique full (case-insensitive di server via uppercase
            // normalize) → mencegah 2 promo dgn kode sama yg bisa
            // confusing kasir. Owner harus pakai kode unik.
            $table->string('voucher_code', 32)->nullable()->after('config');
            $table->unique('voucher_code');
        });
    }

    public function down(): void
    {
        Schema::table('promos', function (Blueprint $table) {
            $table->dropUnique(['voucher_code']);
            $table->dropColumn('voucher_code');
        });
    }
};
