<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\CompoundRecipe;
use App\Models\Tenant\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Compound recipe master.
 *
 * - "Yield" product MUST be type=compoundable_drug.
 * - Components MUST be inventory-tracked (anything but pure service).
 * - Each component's `unit_id` MUST exist in the component product's
 *   product_units (so UnitConverter can resolve a factor).
 */
class CompoundController extends Controller
{
    public function index(): Response
    {
        $this->authorize('master.compounds');

        $recipes = CompoundRecipe::with([
            'product:id,sku,name',
            'yieldUnit:id,code,name',
            'components.component:id,sku,name',
            'components.unit:id,code,name',
        ])->orderBy('id', 'desc')->get();

        // Candidates for the "yield" select.
        $yieldProducts = Product::query()
            ->where('type', Product::TYPE_COMPOUNDABLE_DRUG)
            ->where('is_active', true)
            ->with(['units:id,product_id,unit_id', 'units.unit:id,code,name'])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'base_unit_id']);

        // Candidates for components — anything trackable.
        $componentProducts = Product::query()
            ->whereNotIn('type', [Product::TYPE_SERVICE])
            ->where('is_active', true)
            ->with(['units:id,product_id,unit_id', 'units.unit:id,code,name'])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'type', 'base_unit_id']);

        return Inertia::render('Master/Compounds', [
            'recipes' => $recipes,
            'yieldProducts' => $yieldProducts,
            'componentProducts' => $componentProducts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('master.compounds');

        $data = $this->validateRecipe($request);

        DB::transaction(function () use ($data) {
            $recipe = CompoundRecipe::create([
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'yield_qty' => $data['yield_qty'],
                'yield_unit_id' => $data['yield_unit_id'],
                'racik_fee' => $data['racik_fee'] ?? 0,
                'markup_percent' => $data['markup_percent'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            foreach ($data['components'] as $row) {
                $recipe->components()->create([
                    'component_product_id' => $row['component_product_id'],
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'],
                ]);
            }
        });

        return back()->with('success', 'Resep racikan ditambahkan.');
    }

    public function update(Request $request, CompoundRecipe $recipe): RedirectResponse
    {
        $this->authorize('master.compounds');

        $data = $this->validateRecipe($request, $recipe->id);

        DB::transaction(function () use ($recipe, $data) {
            $recipe->update([
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'yield_qty' => $data['yield_qty'],
                'yield_unit_id' => $data['yield_unit_id'],
                'racik_fee' => $data['racik_fee'] ?? 0,
                'markup_percent' => $data['markup_percent'] ?? 0,
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            // Replace components — simpler than diffing and the volume is small.
            $recipe->components()->delete();
            foreach ($data['components'] as $row) {
                $recipe->components()->create([
                    'component_product_id' => $row['component_product_id'],
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'],
                ]);
            }
        });

        return back()->with('success', 'Resep racikan diperbarui.');
    }

    public function destroy(CompoundRecipe $recipe): RedirectResponse
    {
        $this->authorize('master.compounds');

        $name = $recipe->name;
        $recipe->delete();

        return back()->with('success', "Resep '{$name}' dihapus.");
    }

    private function validateRecipe(Request $request, ?int $recipeId = null): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'yield_qty' => ['required', 'numeric', 'min:0.0001'],
            'yield_unit_id' => ['required', 'integer', 'exists:master_units,id'],
            'racik_fee' => ['nullable', 'numeric', 'min:0'],
            'markup_percent' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'components' => ['required', 'array', 'min:1'],
            'components.*.component_product_id' => ['required', 'integer', 'exists:products,id'],
            'components.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'components.*.unit_id' => ['required', 'integer', 'exists:master_units,id'],
        ]);

        // Yield product MUST be a compoundable_drug.
        $yield = Product::findOrFail($data['product_id']);
        if ($yield->type !== Product::TYPE_COMPOUNDABLE_DRUG) {
            abort(422, "Produk hasil resep harus bertipe compoundable_drug.");
        }

        // Yield unit MUST exist as a product_unit on the yield product.
        if (! $yield->units()->where('unit_id', $data['yield_unit_id'])->exists()) {
            abort(422, "Yield unit tidak terdaftar pada produk '{$yield->name}'.");
        }

        // Component unit + self-reference + duplicate checks.
        $seen = [];
        foreach ($data['components'] as $i => $row) {
            if ((int) $row['component_product_id'] === (int) $data['product_id']) {
                abort(422, "Komponen #".($i + 1).": tidak boleh sama dengan produk hasil.");
            }
            $key = (int) $row['component_product_id'];
            if (isset($seen[$key])) {
                abort(422, "Komponen #".($i + 1).": produk duplikat.");
            }
            $seen[$key] = true;

            $component = Product::find($row['component_product_id']);
            if (! $component || $component->type === Product::TYPE_SERVICE) {
                abort(422, "Komponen #".($i + 1).": produk tidak valid (service tidak tracking stok).");
            }
            if (! $component->units()->where('unit_id', $row['unit_id'])->exists()) {
                abort(422, "Komponen #".($i + 1)." ({$component->name}): unit tidak terdaftar pada produk ini.");
            }
        }

        return $data;
    }
}
