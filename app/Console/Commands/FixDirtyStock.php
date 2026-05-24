<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use Illuminate\Console\Command;

/**
 * Reset "dirty" inventory rows (qty < 0.01 tapi > 0) ke nilai seed demo,
 * agar lingkungan demo bisa di-retest bersih setelah ada typo opname.
 *
 * Background: dulu UI counting + Excel parser nerima qty 0,0002 secara
 * diam-diam (PhpSpreadsheet parse "0,12" lokal-ID jadi 0.12 / 0.0012
 * tergantung locale). Itu sudah ditambal di input layer, tapi inventory
 * yang sudah kotor perlu di-reset manual.
 *
 * Dipanggil per-tenant: `php artisan demo:fix-dirty-stock demo`.
 */
class FixDirtyStock extends Command
{
    protected $signature = 'demo:fix-dirty-stock
                            {tenant : Tenant slug (mis. demo)}
                            {--dry-run : Tampilkan rencana saja, jangan tulis}';

    protected $description = 'Reset inventory dengan qty kotor (0 < qty < 0.01) ke nilai seed demo.';

    /**
     * Nilai seed default per SKU (sumber: DemoSeeder::run()). Kalau SKU
     * tidak ada di map ini, qty dianggap "tidak diketahui asalnya" dan
     * akan di-set 0 supaya tidak bocor ke laporan.
     */
    private const DEMO_SEED_QTY = [
        'SKU-001' => 100,    // Royal Canin (umpama)
        'SKU-002' => 50,
        'SKU-003' => 30,
        'RAW-AMOX' => 50000, // mg
        'RAW-AQUA' => 2000,  // ml
        'RAW-BOTOL60' => 50,
        'RAW-VAKRAB' => 30,  // ← root case yg user lapor (0,0002 vial)
        'RAW-SPUIT3' => 100,
        'RAW-KAPAS' => 5,
    ];

    public function handle(): int
    {
        $slug = $this->argument('tenant');
        $tenant = Tenant::find($slug);
        if (! $tenant) {
            $this->error("Tenant '{$slug}' tidak ditemukan.");

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        $result = $tenant->run(function () use ($dry) {
            $dirty = Inventory::query()
                ->withoutGlobalScopes()
                ->join('products', 'products.id', '=', 'inventories.product_id')
                ->join('warehouses', 'warehouses.id', '=', 'inventories.warehouse_id')
                ->whereRaw('inventories.qty > 0')
                ->whereRaw('inventories.qty < 0.01')
                ->select(
                    'inventories.id as inv_id',
                    'inventories.qty as dirty_qty',
                    'products.id as product_id',
                    'products.sku',
                    'products.name as product_name',
                    'warehouses.code as wh_code',
                    'warehouses.name as wh_name',
                )
                ->orderBy('products.sku')
                ->get();

            if ($dirty->isEmpty()) {
                $this->info('Tidak ada inventory dengan qty kotor. Bersih.');

                return ['fixed' => 0, 'zeroed' => 0];
            }

            $rows = $dirty->map(function ($r) {
                $seed = self::DEMO_SEED_QTY[$r->sku] ?? null;

                return [
                    'sku' => $r->sku,
                    'product' => $r->product_name,
                    'warehouse' => "{$r->wh_name} ({$r->wh_code})",
                    'before' => rtrim(rtrim((string) $r->dirty_qty, '0'), '.'),
                    'after' => $seed !== null ? (string) $seed : '0 (unknown)',
                    'inv_id' => $r->inv_id,
                ];
            });

            $this->table(
                ['SKU', 'Produk', 'Gudang', 'Sebelum', 'Sesudah'],
                $rows->map(fn ($r) => [$r['sku'], $r['product'], $r['warehouse'], $r['before'], $r['after']])->all(),
            );

            if ($dry) {
                $this->warn('Dry-run: tidak ada perubahan ditulis.');

                return ['fixed' => 0, 'zeroed' => 0];
            }

            $fixed = 0;
            $zeroed = 0;
            foreach ($rows as $r) {
                $seed = self::DEMO_SEED_QTY[$r['sku']] ?? null;
                Inventory::withoutGlobalScopes()
                    ->where('id', $r['inv_id'])
                    ->update(['qty' => $seed ?? 0, 'updated_at' => now()]);
                $seed !== null ? $fixed++ : $zeroed++;
            }

            return ['fixed' => $fixed, 'zeroed' => $zeroed];
        });

        $this->info("Selesai. Reset ke nilai seed: {$result['fixed']} · Zeroed (SKU asing): {$result['zeroed']}.");

        return self::SUCCESS;
    }
}
