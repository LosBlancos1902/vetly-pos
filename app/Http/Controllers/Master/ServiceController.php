<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Product;
use App\Models\Tenant\ServiceBundle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Service bundle master (tindakan klinik).
 *
 * - Bundle's "product" MUST be type=service OR service_with_consumption.
 * - service_with_consumption bundles consume real inventory items on sale.
 * - Each component's `unit_id` MUST exist in the component product's
 *   product_units (UnitConverter resolves the factor on sale).
 */
class ServiceController extends Controller
{
    public function index(): Response
    {
        $this->authorize('master.services');

        $bundles = ServiceBundle::with([
            'product:id,sku,name,type',
            'items.component:id,sku,name',
            'items.unit:id,code,name',
        ])->orderBy('id', 'desc')->get();

        $serviceProducts = Product::query()
            ->whereIn('type', [Product::TYPE_SERVICE, Product::TYPE_SERVICE_WITH_CONSUMPTION])
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'type', 'base_unit_id']);

        $componentProducts = Product::query()
            ->whereNotIn('type', [Product::TYPE_SERVICE])
            ->where('is_active', true)
            ->with(['units:id,product_id,unit_id', 'units.unit:id,code,name'])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'type', 'base_unit_id']);

        return Inertia::render('Master/Services', [
            'bundles' => $bundles,
            'serviceProducts' => $serviceProducts,
            'componentProducts' => $componentProducts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('master.services');

        $data = $this->validateBundle($request);

        DB::transaction(function () use ($data) {
            $bundle = ServiceBundle::create([
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'service_fee' => $data['service_fee'],
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            foreach ($data['items'] ?? [] as $row) {
                $bundle->items()->create([
                    'component_product_id' => $row['component_product_id'],
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'],
                    'is_optional' => $row['is_optional'] ?? false,
                ]);
            }
        });

        return back()->with('success', 'Service bundle ditambahkan.');
    }

    public function update(Request $request, ServiceBundle $bundle): RedirectResponse
    {
        $this->authorize('master.services');

        $data = $this->validateBundle($request, $bundle->id);

        DB::transaction(function () use ($bundle, $data) {
            $bundle->update([
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'service_fee' => $data['service_fee'],
                'notes' => $data['notes'] ?? null,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $bundle->items()->delete();
            foreach ($data['items'] ?? [] as $row) {
                $bundle->items()->create([
                    'component_product_id' => $row['component_product_id'],
                    'qty' => $row['qty'],
                    'unit_id' => $row['unit_id'],
                    'is_optional' => $row['is_optional'] ?? false,
                ]);
            }
        });

        return back()->with('success', 'Service bundle diperbarui.');
    }

    public function destroy(ServiceBundle $bundle): RedirectResponse
    {
        $this->authorize('master.services');

        $name = $bundle->name;
        $bundle->delete();

        return back()->with('success', "Bundle '{$name}' dihapus.");
    }

    private function validateBundle(Request $request, ?int $bundleId = null): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'service_fee' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['boolean'],
            'items' => ['nullable', 'array'],
            'items.*.component_product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.qty' => ['required', 'numeric', 'min:0.0001'],
            'items.*.unit_id' => ['required', 'integer', 'exists:master_units,id'],
            'items.*.is_optional' => ['boolean'],
        ]);

        $service = Product::findOrFail($data['product_id']);
        if (! in_array($service->type, [Product::TYPE_SERVICE, Product::TYPE_SERVICE_WITH_CONSUMPTION], true)) {
            abort(422, "Produk bundle harus bertipe service / service_with_consumption.");
        }

        // service_with_consumption MUST have at least one component.
        if ($service->type === Product::TYPE_SERVICE_WITH_CONSUMPTION
            && empty($data['items'])) {
            abort(422, "Service with consumption wajib punya minimal 1 komponen.");
        }

        $seen = [];
        foreach (($data['items'] ?? []) as $i => $row) {
            if ((int) $row['component_product_id'] === (int) $data['product_id']) {
                abort(422, "Komponen #".($i + 1).": tidak boleh sama dengan produk jasa.");
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
