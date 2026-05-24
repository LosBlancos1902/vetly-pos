<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Denorm convenience — sum dari promo_applications.discount_amount
            // utk sale ini. sales.discount_amount TIDAK diubah (tetap = diskon
            // manual per-item yg di-override kasir).
            //
            // Total = subtotal - discount_amount - promo_discount_amount
            $table->decimal('promo_discount_amount', 15, 2)
                ->default(0)
                ->after('change_amount');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('promo_discount_amount');
        });
    }
};
