<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Chart of Accounts — struktur retail Indonesia.
 * normal_balance: asset/expense/cogs = debit; liability/equity/revenue = credit
 * (4199 Diskon Penjualan is a contra-revenue account => debit).
 */
class CoaSeeder extends Seeder
{
    public function run(): void
    {
        // [code, name, type, normal_balance, parent_code|null, level]
        $accounts = [
            ['1100', 'Kas', 'asset', 'debit', null, 1],
            ['1101', 'Kas Besar', 'asset', 'debit', '1100', 2],
            ['1102', 'Kas Kecil', 'asset', 'debit', '1100', 2],
            ['1103', 'Bank BCA', 'asset', 'debit', '1100', 2],
            ['1104', 'Bank Mandiri', 'asset', 'debit', '1100', 2],
            ['1105', 'Piutang QRIS', 'asset', 'debit', '1100', 2],
            ['1106', 'Piutang Customer', 'asset', 'debit', '1100', 2],

            ['1200', 'Persediaan', 'asset', 'debit', null, 1],
            ['1201', 'Persediaan Retail', 'asset', 'debit', '1200', 2],
            ['1202', 'Persediaan Konsinyasi', 'asset', 'debit', '1200', 2],
            ['1203', 'Barang Dalam Perjalanan', 'asset', 'debit', '1200', 2],

            ['2100', 'Hutang', 'liability', 'credit', null, 1],
            ['2101', 'Hutang Supplier', 'liability', 'credit', '2100', 2],
            ['2102', 'Hutang Pajak', 'liability', 'credit', '2100', 2],

            ['3100', 'Modal', 'equity', 'credit', null, 1],
            ['3101', 'Modal Pemilik', 'equity', 'credit', '3100', 2],
            ['3102', 'Laba Ditahan', 'equity', 'credit', '3100', 2],

            ['4100', 'Penjualan', 'revenue', 'credit', null, 1],
            ['4101', 'Penjualan Retail', 'revenue', 'credit', '4100', 2],
            ['4102', 'Penjualan Online', 'revenue', 'credit', '4100', 2],
            ['4199', 'Diskon Penjualan', 'revenue', 'debit', '4100', 2], // contra-revenue

            ['5100', 'HPP', 'cogs', 'debit', null, 1],

            ['6100', 'Beban', 'expense', 'debit', null, 1],
            ['6101', 'Beban Gaji', 'expense', 'debit', '6100', 2],
            ['6102', 'Beban MDR QRIS', 'expense', 'debit', '6100', 2],
            ['6103', 'Beban Sewa', 'expense', 'debit', '6100', 2],
            ['6104', 'Beban Listrik', 'expense', 'debit', '6100', 2],
            ['6105', 'Beban Internet', 'expense', 'debit', '6100', 2],
            ['6199', 'Beban Lain-lain', 'expense', 'debit', '6100', 2],
        ];

        // Pass 1: insert/update all rows without parent.
        foreach ($accounts as [$code, $name, $type, $nb, $parentCode, $level]) {
            DB::table('coa')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'normal_balance' => $nb,
                    'level' => $level,
                    'is_active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        // Pass 2: wire parent_id by code.
        $idByCode = DB::table('coa')->pluck('id', 'code');
        foreach ($accounts as [$code, , , , $parentCode]) {
            if ($parentCode !== null) {
                DB::table('coa')->where('code', $code)
                    ->update(['parent_id' => $idByCode[$parentCode] ?? null]);
            }
        }
    }
}
