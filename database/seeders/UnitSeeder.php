<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'pcs',   'name' => 'Pieces', 'is_base' => true],
            ['code' => 'pack',  'name' => 'Pack',   'is_base' => false],
            ['code' => 'dus',   'name' => 'Dus',    'is_base' => false],
            ['code' => 'kg',    'name' => 'Kilogram', 'is_base' => true],
            ['code' => 'gram',  'name' => 'Gram',   'is_base' => false],
            ['code' => 'liter', 'name' => 'Liter',  'is_base' => true],
            ['code' => 'ml',    'name' => 'Mililiter', 'is_base' => false],
            ['code' => 'mg',    'name' => 'Miligram', 'is_base' => false],
            ['code' => 'box',   'name' => 'Box',    'is_base' => false],
            ['code' => 'vial',  'name' => 'Vial',   'is_base' => true],
            ['code' => 'btl',   'name' => 'Botol',  'is_base' => false],
        ];

        foreach ($units as $u) {
            DB::table('master_units')->updateOrInsert(
                ['code' => $u['code']],
                ['name' => $u['name'], 'is_base' => $u['is_base'], 'created_at' => now()],
            );
        }
    }
}
