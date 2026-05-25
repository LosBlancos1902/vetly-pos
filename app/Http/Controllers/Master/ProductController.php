<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\ProductValidator;
use App\Models\Tenant\Brand;
use App\Models\Tenant\Category;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\PriceTier;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductUnit;
use App\Models\Tenant\ProductUnitPrice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::with(['category:id,name', 'brand:id,name', 'baseUnit:id,code'])
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('sku', $s)
                ->orWhere('barcode', $s)))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Master/Products', [
            'products' => $products,
            'categories' => Category::where('is_active', true)->get(['id', 'name']),
            'brands' => Brand::where('is_active', true)->get(['id', 'name']),
            'units' => MasterUnit::all(['id', 'code', 'name']),
            'tiers' => PriceTier::orderBy('sort_order')
                ->get(['id', 'name', 'sort_order', 'is_default', 'is_active']),
            'filters' => $request->only('search'),
        ]);
    }

    /**
     * JSON detail untuk modal edit (di-fetch via axios/router.visit).
     * Bukan Inertia render — caller cuma butuh data nested.
     */
    public function show(Product $product): JsonResponse
    {
        $this->authorize('master.manage');

        $product->load([
            'category:id,name',
            'brand:id,name',
            'baseUnit:id,code,name',
            'units.unit:id,code,name',
            'units.prices:id,product_unit_id,price_tier_id,price',
            // Stok per gudang untuk section "Stok per Gudang" di modal master.
            // Bypass WarehouseScope: owner/manager perlu lihat lintas cabang
            // walaupun mereka punya warehouse_id (tidak akan, tapi defensive).
            'inventories' => fn ($q) => $q->withoutGlobalScopes()
                ->with('warehouse:id,code,name,warehouse_type,is_active')
                ->orderByDesc('qty'),
        ]);

        return response()->json(['product' => $product]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('master.manage');

        $data = ProductValidator::validate($request);

        DB::transaction(function () use ($data) {
            $baseUnit = collect($data['units'])->firstWhere('level', 1);

            $product = Product::create([
                'sku' => $data['sku'],
                'name' => $data['name'],
                'barcode' => $data['barcode'] ?? null,
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'],
                'brand_id' => $data['brand_id'] ?? null,
                'base_unit_id' => $baseUnit['unit_id'],
                'type' => $data['type'],
                'price' => $this->basePriceForLegacy($data),
                'cost_avg' => 0,
                'min_stock' => $data['min_stock'] ?? 0,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncUnitsAndPrices($product, $data['units']);
        });

        return back()->with('success', 'Produk ditambahkan.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $this->authorize('master.manage');

        $data = ProductValidator::validate($request, $product);

        DB::transaction(function () use ($data, $product) {
            $baseUnit = collect($data['units'])->firstWhere('level', 1);

            $product->update([
                'sku' => $data['sku'] ?? $product->sku,
                'name' => $data['name'],
                'barcode' => $data['barcode'] ?? null,
                'description' => $data['description'] ?? null,
                'category_id' => $data['category_id'],
                'brand_id' => $data['brand_id'] ?? null,
                'base_unit_id' => $baseUnit['unit_id'],
                'type' => $data['type'],
                'price' => $this->basePriceForLegacy($data),
                'min_stock' => $data['min_stock'] ?? 0,
                'is_active' => $data['is_active'] ?? $product->is_active,
            ]);

            // Replace-all: cleaner than diff-merge untuk nested matrix harga.
            // Cascade FK akan urus product_unit_prices yang ikut terhapus.
            $this->syncUnitsAndPrices($product, $data['units'], replace: true);
        });

        return back()->with('success', 'Produk diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $this->authorize('master.manage');

        // Soft "nonaktif" kalau ada histori. Hard delete cuma untuk produk
        // yg belum pernah dipakai.
        $hasHistory = $product->inventories()->exists()
            || DB::table('sales_items')->where('product_id', $product->id)->exists();

        if ($hasHistory) {
            $product->update(['is_active' => false]);

            return back()->with('success', "Produk '{$product->name}' dinonaktifkan (ada histori).");
        }

        $product->delete(); // cascade ke product_units → product_unit_prices

        return back()->with('success', "Produk '{$product->name}' dihapus.");
    }

    /**
     * Sync nested product_units + product_unit_prices.
     */
    private function syncUnitsAndPrices(Product $product, array $unitsData, bool $replace = false): void
    {
        if ($replace) {
            ProductUnit::where('product_id', $product->id)->delete();
        }

        foreach ($unitsData as $u) {
            $unit = ProductUnit::create([
                'product_id' => $product->id,
                'unit_id' => $u['unit_id'],
                'level' => $u['level'],
                'conversion_to_base' => $u['conversion_to_base'],
                'is_purchase_unit' => $u['is_purchase_unit'] ?? true,
                'is_sale_unit' => $u['is_sale_unit'] ?? true,
                'barcode_per_unit' => $u['barcode_per_unit'] ?? null,
            ]);

            foreach ($u['prices'] ?? [] as $p) {
                ProductUnitPrice::create([
                    'product_unit_id' => $unit->id,
                    'price_tier_id' => $p['price_tier_id'],
                    'price' => $p['price'],
                ]);
            }
        }
    }

    /**
     * Auto-isi products.price (kolom legacy) dari harga base-unit tier
     * default supaya POS legacy + report eksternal yang baca kolom ini
     * tetap valid.
     */
    private function basePriceForLegacy(array $data): float
    {
        $defaultTierId = PriceTier::where('is_default', true)->value('id');
        if ($defaultTierId === null) {
            return 0;
        }

        $baseUnit = collect($data['units'])->firstWhere('level', 1);
        $basePrices = collect($baseUnit['prices'] ?? []);
        $defaultPrice = $basePrices->firstWhere('price_tier_id', $defaultTierId);

        return (float) ($defaultPrice['price'] ?? 0);
    }
}
