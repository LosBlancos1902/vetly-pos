<?php

namespace Database\Seeders;

use App\Models\Tenant\Product;
use App\Models\Tenant\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Demo data for the `demo` tenant — petshop + klinik realistis.
 * NOT part of TenantDatabaseSeeder — only run explicitly for the demo tenant.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        // Owner user (warehouse_id NULL = lihat semua)
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

        // Apoteker demo user — production owner yang assign via /settings/users.
        // Seeded here hanya supaya tester bisa login langsung sebagai apoteker.
        // warehouse_id akan diisi setelah warehouse dibuat di bawah.
        $apoteker = User::firstOrCreate(
            ['email' => 'apoteker@vetly.id'],
            [
                'name' => 'Apoteker Demo',
                'password' => Hash::make('demo123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $apoteker->assignRole('apoteker');

        // Warehouse default
        DB::table('warehouses')->updateOrInsert(
            ['code' => 'TOKO-DEMO'],
            [
                'name' => 'Toko Demo',
                'warehouse_type' => 'petshop',
                'is_active' => true,
                'is_default' => true,
                'address' => 'Jakarta',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $warehouseId = (int) DB::table('warehouses')->where('code', 'TOKO-DEMO')->value('id');

        // Pin apoteker ke warehouse default (rule: staff MUST have warehouse_id).
        $apoteker->update(['warehouse_id' => $warehouseId]);

        // Category + brand
        $catId = DB::table('categories')->insertGetId([
            'name' => 'Umum', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $brandId = DB::table('brands')->insertGetId([
            'name' => 'No Brand', 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $units = DB::table('master_units')->pluck('id', 'code');

        // === RETAIL (5 existing) ===
        $retailProducts = [
            ['SKU-001', '8990001000017', 'Royal Canin Kitten 1kg', 95000, 145000, 'pcs'],
            ['SKU-002', '8990001000024', 'Whiskas Tuna 85g', 6500, 9500, 'pcs'],
            ['SKU-003', '8990001000031', 'Vitamin Kucing Drops 30ml', 28000, 45000, 'pcs'],
            ['SKU-004', '8990001000048', 'Pasir Wangi 10L', 32000, 52000, 'pcs'],
            ['SKU-005', '8990001000055', 'Mainan Tikus Catnip', 8000, 18000, 'pcs'],
        ];
        foreach ($retailProducts as [$sku, $barcode, $name, $cost, $price, $unit]) {
            $this->seedSimpleProduct(
                sku: $sku, barcode: $barcode, name: $name,
                catId: $catId, brandId: $brandId,
                unitId: (int) $units[$unit],
                type: Product::TYPE_SALEABLE_RETAIL,
                cost: $cost, price: $price,
                warehouseId: $warehouseId, qty: 20,
            );
        }

        // === RAW MATERIALS (klinik) ===
        // Amoxicillin powder: bulk 5000mg per botol; base unit = mg, cost per mg = Rp 10.
        $amoxId = $this->seedSimpleProduct(
            sku: 'RAW-AMOX', barcode: null, name: 'Amoxicillin Powder (mg)',
            catId: $catId, brandId: $brandId, unitId: (int) $units['mg'],
            type: Product::TYPE_RAW_MATERIAL, cost: 10, price: 0,
            warehouseId: $warehouseId, qty: 50000, isSellableDirectly: false,
        );
        // Aquadest: base unit ml, cost Rp 50/ml.
        $aquaId = $this->seedSimpleProduct(
            sku: 'RAW-AQUA', barcode: null, name: 'Aquadest (ml)',
            catId: $catId, brandId: $brandId, unitId: (int) $units['ml'],
            type: Product::TYPE_RAW_MATERIAL, cost: 50, price: 0,
            warehouseId: $warehouseId, qty: 2000, isSellableDirectly: false,
        );
        // Botol 60ml kosong: base = pcs, cost 1500/pcs.
        $botolId = $this->seedSimpleProduct(
            sku: 'RAW-BOTOL60', barcode: null, name: 'Botol Kosong 60ml',
            catId: $catId, brandId: $brandId, unitId: (int) $units['pcs'],
            type: Product::TYPE_RAW_MATERIAL, cost: 1500, price: 0,
            warehouseId: $warehouseId, qty: 50, isSellableDirectly: false,
        );
        // Vial vaksin rabies: base = vial, cost 75000.
        $vialRabId = $this->seedSimpleProduct(
            sku: 'RAW-VAKRAB', barcode: null, name: 'Vial Vaksin Rabies',
            catId: $catId, brandId: $brandId, unitId: (int) $units['vial'],
            type: Product::TYPE_RAW_MATERIAL, cost: 75000, price: 0,
            warehouseId: $warehouseId, qty: 30, isSellableDirectly: false,
        );
        // Spuit 3ml: base = pcs, cost 2500.
        $spuitId = $this->seedSimpleProduct(
            sku: 'RAW-SPUIT3', barcode: null, name: 'Spuit 3ml',
            catId: $catId, brandId: $brandId, unitId: (int) $units['pcs'],
            type: Product::TYPE_RAW_MATERIAL, cost: 2500, price: 0,
            warehouseId: $warehouseId, qty: 100, isSellableDirectly: false,
        );
        // Kapas alkohol: base = box (100 pcs/box). Cost per box 35000.
        $kapasId = $this->seedSimpleProduct(
            sku: 'RAW-KAPAS', barcode: null, name: 'Kapas Alkohol (box 100pcs)',
            catId: $catId, brandId: $brandId, unitId: (int) $units['box'],
            type: Product::TYPE_RAW_MATERIAL, cost: 35000, price: 0,
            warehouseId: $warehouseId, qty: 5, isSellableDirectly: false,
        );

        // === COMPOUND PRODUCT (output of recipe) ===
        // Amoxicillin Sirup 250mg/5ml — sold per ml.
        // 60ml yields 60 base units (ml). Cost will be derived per execute().
        $compoundProductId = $this->seedSimpleProduct(
            sku: 'CPD-AMOXSIR', barcode: null, name: 'Amoxicillin Sirup 250mg/5ml',
            catId: $catId, brandId: $brandId, unitId: (int) $units['ml'],
            type: Product::TYPE_COMPOUNDABLE_DRUG, cost: 0, price: 0,
            warehouseId: $warehouseId, qty: 0, requiresPrescription: true,
        );

        // Recipe: 60ml yield = 1500mg amoxicillin + 60ml aquadest + 1 botol
        // cost batch = 15000 + 3000 + 1500 = 19500
        // suggestPrice = (19500 + 5000) * 1.5 / 60 = 612.50 per ml
        $recipeId = DB::table('compound_recipes')->insertGetId([
            'product_id' => $compoundProductId,
            'name' => 'Amoxicillin Sirup 250mg/5ml (60ml)',
            'yield_qty' => 60,
            'yield_unit_id' => (int) $units['ml'],
            'racik_fee' => 5000,
            'markup_percent' => 50,
            'notes' => 'Resep standar 60ml botol',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('compound_recipe_components')->insert([
            ['recipe_id' => $recipeId, 'component_product_id' => $amoxId,  'qty' => 1500, 'unit_id' => (int) $units['mg'],  'created_at' => now(), 'updated_at' => now()],
            ['recipe_id' => $recipeId, 'component_product_id' => $aquaId,  'qty' => 60,   'unit_id' => (int) $units['ml'],  'created_at' => now(), 'updated_at' => now()],
            ['recipe_id' => $recipeId, 'component_product_id' => $botolId, 'qty' => 1,    'unit_id' => (int) $units['pcs'], 'created_at' => now(), 'updated_at' => now()],
        ]);
        // Set compound's products.price to suggested price (computed offline above: 612.50).
        DB::table('products')->where('id', $compoundProductId)
            ->update(['price' => 612.50, 'updated_at' => now()]);

        // === SERVICE BUNDLE ===
        // "Vaksin Rabies" — service_fee 250000, konsumsi: 1 vial + 1 spuit + 0.01 box kapas.
        $serviceProductId = $this->seedSimpleProduct(
            sku: 'SVC-VAKRAB', barcode: null, name: 'Vaksin Rabies (jasa)',
            catId: $catId, brandId: $brandId, unitId: (int) $units['pcs'],
            type: Product::TYPE_SERVICE_WITH_CONSUMPTION, cost: 0, price: 250000,
            warehouseId: $warehouseId, qty: 0,
        );
        $bundleId = DB::table('service_bundles')->insertGetId([
            'product_id' => $serviceProductId,
            'name' => 'Vaksin Rabies',
            'service_fee' => 250000,
            'notes' => 'Termasuk vial + spuit + kapas',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_bundle_items')->insert([
            ['bundle_id' => $bundleId, 'component_product_id' => $vialRabId, 'qty' => 1,    'unit_id' => (int) $units['vial'], 'is_optional' => false, 'created_at' => now(), 'updated_at' => now()],
            ['bundle_id' => $bundleId, 'component_product_id' => $spuitId,   'qty' => 1,    'unit_id' => (int) $units['pcs'],  'is_optional' => false, 'created_at' => now(), 'updated_at' => now()],
            ['bundle_id' => $bundleId, 'component_product_id' => $kapasId,  'qty' => 0.01, 'unit_id' => (int) $units['box'],  'is_optional' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function seedSimpleProduct(
        string $sku, ?string $barcode, string $name,
        int $catId, int $brandId, int $unitId,
        string $type, float $cost, float $price,
        int $warehouseId, float $qty,
        bool $isSellableDirectly = true,
        bool $requiresPrescription = false,
    ): int {
        $pid = DB::table('products')->insertGetId([
            'sku' => $sku, 'barcode' => $barcode, 'name' => $name,
            'category_id' => $catId, 'brand_id' => $brandId,
            'base_unit_id' => $unitId, 'type' => $type,
            'cost_avg' => $cost, 'price' => $price,
            'min_stock' => 0, 'is_active' => true,
            'is_sellable_directly' => $isSellableDirectly,
            'requires_prescription' => $requiresPrescription,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('product_units')->insert([
            'product_id' => $pid, 'unit_id' => $unitId,
            'level' => 1, 'conversion_to_base' => 1,
            'is_purchase_unit' => true, 'is_sale_unit' => true,
            'price' => $price > 0 ? $price : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'product_id' => $pid, 'warehouse_id' => $warehouseId,
            'qty' => $qty, 'cost_avg' => $cost,
            'last_movement_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $pid;
    }
}
