<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Brand;
use App\Models\Tenant\Category;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        $products = Product::with(['category:id,name', 'brand:id,name', 'baseUnit:id,code'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('sku', $s)->orWhere('barcode', $s))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Master/Products', [
            'products' => $products,
            'categories' => Category::where('is_active', true)->get(['id', 'name']),
            'brands' => Brand::where('is_active', true)->get(['id', 'name']),
            'units' => MasterUnit::all(['id', 'code', 'name']),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'unique:products,sku'],
            'barcode' => ['nullable', 'string'],
            'name' => ['required', 'string'],
            'category_id' => ['nullable', 'integer'],
            'brand_id' => ['nullable', 'integer'],
            'base_unit_id' => ['required', 'integer'],
            'price' => ['required', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
        ]);

        Product::create($data);

        return back()->with('success', 'Produk ditambahkan.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $product->update($request->only([
            'barcode', 'name', 'description', 'category_id', 'brand_id',
            'price', 'min_stock', 'max_stock', 'is_active', 'allow_stock_minus',
        ]));

        return back()->with('success', 'Produk diperbarui.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        return back()->with('success', 'Produk dihapus.');
    }
}
