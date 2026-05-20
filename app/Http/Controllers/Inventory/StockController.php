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
    public function index(): Response
    {
        return Inertia::render('Inventory/Stock', [
            'inventories' => Inventory::with(['product:id,sku,name,min_stock', 'warehouse:id,name'])
                ->orderByDesc('id')
                ->paginate(25),
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
