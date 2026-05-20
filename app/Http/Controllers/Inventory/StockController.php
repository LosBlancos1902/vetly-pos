<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\Tenant\Warehouse;
use App\Services\StockMovement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'filters' => [
                'warehouse_id' => $filters['warehouse_id'] ?? null,
                'type' => $filters['type'] ?? null,
                'search' => $filters['search'] ?? null,
            ],
        ]);
    }

    public function adjust(Request $request, StockMovement $stock): RedirectResponse
    {
        $this->authorize('inventory.adjustment');

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'warehouse_id' => ['required', 'integer'],
            'qty' => ['required', 'numeric', 'not_in:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $warehouse = Warehouse::findOrFail($data['warehouse_id']);
        $type = $data['qty'] > 0 ? 'adjustment_plus' : 'adjustment_minus';

        $stock->record($product, $warehouse, $type, abs($data['qty']), (float) $product->cost_avg, [
            'ref_type' => 'manual_adjustment',
            'notes' => $data['notes'] ?? null,
        ]);

        return back()->with('success', 'Penyesuaian stok tersimpan.');
    }
}
