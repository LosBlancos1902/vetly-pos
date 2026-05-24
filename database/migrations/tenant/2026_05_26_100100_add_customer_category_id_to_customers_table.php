<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Kategori CRM (Member/VIP/Reguler/Corporate dll). Nullable
            // supaya customer existing tetap valid + walk-in non-categorized.
            // nullOnDelete: kalau owner hapus kategori, customer tetap ada
            // (kategori set null, no orphan FK).
            $table->foreignId('customer_category_id')->nullable()
                ->after('email')
                ->constrained('customer_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropForeign(['customer_category_id']);
            $table->dropColumn('customer_category_id');
        });
    }
};
