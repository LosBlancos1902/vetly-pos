<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * finance_settings — singleton per tenant DB (pola branding_settings).
 * approval_threshold: pengeluaran/penerimaan > nilai ini butuh approval.
 * effective_date_locked_before: persiapan tutup buku (belum dipakai v1).
 * expense_presets: mapping kategori beban custom (JSON) utk quick-pick.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('approval_threshold', 15, 2)->default(5000000);
            $table->date('effective_date_locked_before')->nullable();
            $table->json('expense_presets')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_settings');
    }
};
