<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COA Editor — metadata kas/bank (non-destruktif, kolom nullable).
 * Distingsi akun kas/bank untuk dropdown sumber transaksi Kas & Bank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coa', function (Blueprint $table) {
            $table->enum('cash_type', ['cash', 'bank'])->nullable()->after('normal_balance');
            $table->string('bank_name')->nullable()->after('cash_type');
            $table->string('account_no')->nullable()->after('bank_name');
        });

        // Tandai akun kas/bank existing (verbatim dari CoaSeeder).
        DB::table('coa')->whereIn('code', ['1101', '1102'])->update(['cash_type' => 'cash']);
        DB::table('coa')->whereIn('code', ['1103', '1104'])->update(['cash_type' => 'bank']);
    }

    public function down(): void
    {
        Schema::table('coa', function (Blueprint $table) {
            $table->dropColumn(['cash_type', 'bank_name', 'account_no']);
        });
    }
};
