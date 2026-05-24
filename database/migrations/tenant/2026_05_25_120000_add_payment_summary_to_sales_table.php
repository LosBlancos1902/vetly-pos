<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // Denormalized payment summary — convenience untuk list/report
            // tanpa harus JOIN sales_payments. Source of truth tetap di
            // sales_payments (1 row per sale, multi-payment SKIP di fase ini).
            //
            // 3 method = scope MVP yg disetujui. sales_payments.method tetap
            // permissive (7 enum) supaya webhook/data lama tidak rusak.
            // Nullable supaya sale pre-F1 tetap valid.
            $table->enum('payment_method', ['cash', 'transfer', 'qris'])
                ->nullable()
                ->after('price_tier_id');
            $table->decimal('amount_paid', 15, 2)
                ->nullable()
                ->after('payment_method');
            $table->decimal('change_amount', 15, 2)
                ->default(0)
                ->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['payment_method', 'amount_paid', 'change_amount']);
        });
    }
};
