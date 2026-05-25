<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class StockController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string'],
            'search' => ['nullable', 'string'],
        ]);

        $query = Inventory::with([
            'product:id,sku,name,type,min_stock',
            'warehouse:id,code,name',
        ]);

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['type'])) {
            $query->whereHas('product', fn ($q) => $q->where('type', $filters['type']));
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->whereHas('product', fn ($q) => $q
                ->where('name', 'like', "%{$s}%")
                ->orWhere('sku', 'like', "%{$s}%"));
        }

        // Ringkasan per-gudang (jumlah SKU dgn qty>0, total nilai stok at HPP,
        // jumlah produk under min_stock). Hanya dihitung kalau user filter ke
        // SATU warehouse — tanpa filter angkanya membingungkan (campur cabang).
        $summary = null;
        $summaryWarehouse = null;
        if (! empty($filters['warehouse_id'])) {
            $summaryWarehouse = Warehouse::query()->withoutGlobalScopes()
                ->find($filters['warehouse_id'], ['id', 'code', 'name', 'warehouse_type']);

            if ($summaryWarehouse) {
                $agg = DB::table('inventories')
                    ->join('products', 'products.id', '=', 'inventories.product_id')
                    ->where('inventories.warehouse_id', $summaryWarehouse->id)
                    ->where('products.is_active', true)
                    ->selectRaw('
                        COUNT(CASE WHEN inventories.qty > 0 THEN 1 END) as sku_count,
                        COALESCE(SUM(inventories.qty * inventories.cost_avg), 0) as total_value,
                        COUNT(CASE WHEN products.min_stock > 0
                            AND inventories.qty <= products.min_stock THEN 1 END) as low_stock_count
                    ')
                    ->first();

                $summary = [
                    'sku_count' => (int) ($agg->sku_count ?? 0),
                    'total_value' => (float) ($agg->total_value ?? 0),
                    'low_stock_count' => (int) ($agg->low_stock_count ?? 0),
                ];
            }
        }

        return Inertia::render('Inventory/Stock', [
            'inventories' => $query->orderByDesc('id')->paginate(25)->withQueryString(),
            'warehouses' => Warehouse::query()->withoutGlobalScopes()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'productTypes' => [
                Product::TYPE_SALEABLE_RETAIL => 'Retail',
                Product::TYPE_COMPOUNDABLE_DRUG => 'Compoundable',
                Product::TYPE_RAW_MATERIAL => 'Raw Material',
                Product::TYPE_SERVICE => 'Service',
                Product::TYPE_SERVICE_WITH_CONSUMPTION => 'Service w/ konsumsi',
            ],
            'summary' => $summary,
            'summaryWarehouse' => $summaryWarehouse,
            'filters' => [
                'warehouse_id' => $filters['warehouse_id'] ?? null,
                'type' => $filters['type'] ?? null,
                'search' => $filters['search'] ?? null,
            ],
        ]);
    }

}
