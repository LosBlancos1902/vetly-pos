<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Category;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Product;
use App\Models\Tenant\Promo;
use App\Models\Tenant\Warehouse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD promo. Fase 1: cuma "Diskon Periode" (Promo::TYPE_PERIODE)
 * yg di-enable di form; 4 tipe lain ada di registry tapi UI grayed-out.
 */
class PromoController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('promo.manage');

        // Definisi status (formal):
        //   active   = is_active=true AND starts_at <= NOW <= ends_at
        //   inactive = is_active=false OR ends_at < NOW (lewat periode)
        //   upcoming = is_active=true AND starts_at > NOW
        //   semua    = no filter
        $now = now();
        $promos = Promo::query()
            ->with(['discountCoa:id,code,name', 'warehouses:id,name'])
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($request->status === 'active', fn ($q) => $q
                ->where('is_active', true)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now))
            ->when($request->status === 'inactive', fn ($q) => $q
                ->where(fn ($w) => $w
                    ->where('is_active', false)
                    ->orWhere('ends_at', '<', $now)))
            ->when($request->status === 'upcoming', fn ($q) => $q
                ->where('is_active', true)
                ->where('starts_at', '>', $now))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Master/Promos', [
            'promos' => $promos,
            // Owner pilih COA dari sini. Filter: type revenue dgn
            // normal_balance=debit (contra-revenue) ATAU type expense —
            // semantically valid utk pos diskon. Reject asset/liability.
            'coas' => Coa::query()
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where(function ($w) {
                        $w->where('type', 'revenue')->where('normal_balance', 'debit');
                    })->orWhere('type', 'expense');
                })
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'type']),
            'warehouses' => Warehouse::where('is_active', true)
                ->orderBy('name')->get(['id', 'code', 'name']),
            // Untuk Tipe 2 Per-Barang picker — semua produk aktif yg sellable
            'products' => Product::where('is_active', true)
                ->where('is_sellable_directly', true)
                ->orderBy('name')->limit(500)->get(['id', 'sku', 'name', 'category_id']),
            'categories' => Category::where('is_active', true)
                ->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('search', 'status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('promo.manage');

        $data = $this->validatePromo($request);

        DB::transaction(function () use ($data, $request) {
            $promo = Promo::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'discount_kind' => $data['discount_kind'],
                'discount_value' => $data['discount_value'],
                'max_discount_amount' => $data['max_discount_amount'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'days_of_week' => $data['days_of_week'] ?? null,
                'time_start' => $data['time_start'] ?? null,
                'time_end' => $data['time_end'] ?? null,
                'discount_coa_id' => $data['discount_coa_id'] ?? null,
                'min_purchase' => $data['min_purchase'] ?? 0,
                'min_qty' => $data['min_qty'] ?? 0,
                'quota_total' => $data['quota_total'] ?? null,
                'config' => $this->buildConfig($data),
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $request->user()->id,
            ]);

            if (! empty($data['warehouse_ids'])) {
                $promo->warehouses()->sync($data['warehouse_ids']);
            }
        });

        return back()->with('success', "Promo '{$data['name']}' ditambahkan.");
    }

    /**
     * Duplicate promo: replicate semua field + config + warehouse pivot.
     * Override: name suffix "(copy)", is_active=false (owner aktifkan
     * manual), quota_used reset 0. quota_total dipertahankan.
     */
    public function duplicate(Promo $promo): RedirectResponse
    {
        $this->authorize('promo.manage');

        DB::transaction(function () use ($promo) {
            $clone = $promo->replicate(['quota_used']);
            $clone->name = $promo->name.' (copy)';
            $clone->is_active = false;
            $clone->quota_used = 0;
            $clone->created_by = auth()->id();
            $clone->save();

            // Copy warehouse pivot juga (kalau ada)
            $whIds = $promo->warehouses->pluck('id')->all();
            if ($whIds !== []) {
                $clone->warehouses()->sync($whIds);
            }
        });

        return back()->with('success', "Promo '{$promo->name}' diduplikasi.");
    }

    public function update(Request $request, Promo $promo): RedirectResponse
    {
        $this->authorize('promo.manage');

        $data = $this->validatePromo($request, $promo->id);

        DB::transaction(function () use ($data, $promo) {
            $promo->update([
                'name' => $data['name'],
                'type' => $data['type'],
                'discount_kind' => $data['discount_kind'],
                'discount_value' => $data['discount_value'],
                'max_discount_amount' => $data['max_discount_amount'] ?? null,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'days_of_week' => $data['days_of_week'] ?? null,
                'time_start' => $data['time_start'] ?? null,
                'time_end' => $data['time_end'] ?? null,
                'discount_coa_id' => $data['discount_coa_id'] ?? null,
                'min_purchase' => $data['min_purchase'] ?? 0,
                'min_qty' => $data['min_qty'] ?? 0,
                'quota_total' => $data['quota_total'] ?? null,
                'config' => $this->buildConfig($data),
                'is_active' => $data['is_active'] ?? $promo->is_active,
            ]);

            // sync warehouses ([] = semua cabang)
            $promo->warehouses()->sync($data['warehouse_ids'] ?? []);
        });

        return back()->with('success', "Promo '{$promo->name}' diperbarui.");
    }

    public function destroy(Promo $promo): RedirectResponse
    {
        $this->authorize('promo.manage');

        // Kalau promo pernah dipakai, soft-deactivate utk jaga audit
        // trail di promo_applications (FK nullable, tapi name di histori
        // hilang kalau hard-delete).
        if ($promo->applications()->exists()) {
            $promo->update(['is_active' => false]);

            return back()->with('success', "Promo '{$promo->name}' dinonaktifkan (ada histori pemakaian).");
        }

        $promo->delete();

        return back()->with('success', "Promo '{$promo->name}' dihapus.");
    }

    private function validatePromo(Request $request, ?int $promoId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                Promo::TYPE_PERIODE,
                Promo::TYPE_PER_ITEM,
                // 3 tipe lain di-disable di UI, server tetap accept (strategy
                // stub return false → tidak pernah apply meski masuk DB).
                Promo::TYPE_VOUCHER,
                Promo::TYPE_BUNDLING,
                Promo::TYPE_TEBUS_MURAH,
            ])],
            'discount_kind' => ['required', 'in:percent,nominal'],
            'discount_value' => ['required', 'numeric', 'min:0.01'],
            'max_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'days_of_week' => ['nullable', 'array'],
            'days_of_week.*' => ['in:mon,tue,wed,thu,fri,sat,sun'],
            'time_start' => ['nullable', 'date_format:H:i,H:i:s'],
            'time_end' => ['nullable', 'date_format:H:i,H:i:s'],
            'discount_coa_id' => ['nullable', 'integer', 'exists:coa,id'],
            'min_purchase' => ['nullable', 'numeric', 'min:0'],
            'min_qty' => ['nullable', 'integer', 'min:0'],
            'quota_total' => ['nullable', 'integer', 'min:1'],
            'warehouse_ids' => ['nullable', 'array'],
            'warehouse_ids.*' => ['integer', 'exists:warehouses,id'],
            // Tipe 2 per_item params
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'category_ids' => ['nullable', 'array'],
            'category_ids.*' => ['integer', 'exists:categories,id'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // Tipe-specific validasi: per_item butuh minimal 1 product/category
        if ($data['type'] === Promo::TYPE_PER_ITEM) {
            $hasProducts = ! empty($data['product_ids']);
            $hasCategories = ! empty($data['category_ids']);
            if (! $hasProducts && ! $hasCategories) {
                abort(422, 'Tipe Per-Barang wajib pilih minimal 1 produk atau 1 kategori.');
            }
        }

        return $data;
    }

    /**
     * Build config JSON dari validated payload. Per-tipe params disimpan
     * di sini supaya schema promos cuma punya 1 JSON column.
     */
    private function buildConfig(array $data): ?array
    {
        if ($data['type'] !== Promo::TYPE_PER_ITEM) {
            return null;
        }

        return [
            'product_ids' => array_values(array_unique(array_map('intval', $data['product_ids'] ?? []))),
            'category_ids' => array_values(array_unique(array_map('intval', $data['category_ids'] ?? []))),
        ];
    }
}
