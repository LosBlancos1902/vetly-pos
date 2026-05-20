<?php

namespace Database\Seeders;

use App\Models\Tenant\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo-only data for the `demo` tenant: 1 warehouse, 5 products, owner user.
 * NOT part of TenantDatabaseSeeder — only run explicitly for the demo tenant.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Owner user
        $owner = User::firstOrCreate(
            ['email' => 'owner@vetly.id'],
            [
                'name' => 'Owner Demo',
                'password' => Hash::make('demo123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $owner->assignRole('owner');

        // Warehouse
        $warehouseId = DB::table('warehouses')->updateOrInsert(
            ['code' => 'TOKO-DEMO'],
            ['name' => 'Toko Demo', 'type' => 'toko', 'is_active' => true, 'address' => 'Jakarta', 'created_at' => now(), 'updated_at' => now()],
        );
        $warehouseId = DB::table('warehouses')->where('code', 'TOKO-DEMO')->value('id');

        // Category + brand
        $catId = DB::table('categories')->insertGetId(['name' => 'Umum', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $brandId = DB::table('brands')->insertGetId(['name' => 'No Brand', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $pcsId = DB::table('master_units')->where('code', 'pcs')->value('id');

        $products = [
            ['SKU-001', '8990001000017', 'Royal Canin Kitten 1kg', 95000, 145000],
            ['SKU-002', '8990001000024', 'Whiskas Tuna 85g', 6500, 9500],
            ['SKU-003', '8990001000031', 'Vitamin Kucing Drops 30ml', 28000, 45000],
            ['SKU-004', '8990001000048', 'Pasir Wangi 10L', 32000, 52000],
            ['SKU-005', '8990001000055', 'Mainan Tikus Catnip', 8000, 18000],
        ];

        foreach ($products as [$sku, $barcode, $name, $cost, $price]) {
            $pid = DB::table('products')->insertGetId([
                'sku' => $sku,
                'barcode' => $barcode,
                'name' => $name,
                'category_id' => $catId,
                'brand_id' => $brandId,
                'base_unit_id' => $pcsId,
                'cost_avg' => $cost,
                'price' => $price,
                'min_stock' => 5,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('product_units')->insert([
                'product_id' => $pid,
                'unit_id' => $pcsId,
                'conversion_to_base' => 1,
                'is_purchase_unit' => true,
                'is_sale_unit' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('inventories')->insert([
                'product_id' => $pid,
                'warehouse_id' => $warehouseId,
                'qty' => 20,
                'cost_avg' => $cost,
                'last_movement_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
