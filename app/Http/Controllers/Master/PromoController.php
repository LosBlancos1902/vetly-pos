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
                'voucher_code' => $data['type'] === Promo::TYPE_VOUCHER
                    ? ($data['voucher_code'] ?? null)
                    : null,
                'is_active' => $data['is_active'] ?? true,
                'is_stackable' => $data['is_stackable'] ?? false,
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
                'voucher_code' => $data['type'] === Promo::TYPE_VOUCHER
                    ? ($data['voucher_code'] ?? null)
                    : null,
                'is_active' => $data['is_active'] ?? $promo->is_active,
                'is_stackable' => $data['is_stackable'] ?? $promo->is_stackable,
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
        // Normalize voucher_code ke UPPERCASE SEBELUM validasi unique
        // supaya 'diskonA' vs 'DISKONA' di-collapse jadi 1 kode.
        if ($request->filled('voucher_code')) {
            $request->merge([
                'voucher_code' => strtoupper(trim((string) $request->voucher_code)),
            ]);
        }

        // Tipe Tebus Murah: diskon dihitung dari selisih (cartPrice − tebus_price),
        // BUKAN dari discount_kind/value. Auto-fill server supaya lulus validator
        // existing (discount_value required min 0.01). UI hide field ini.
        if ($request->input('type') === Promo::TYPE_TEBUS_MURAH) {
            $request->merge([
                'discount_kind' => 'nominal',
                'discount_value' => 1, // dummy, tidak dipakai strategy
            ]);
        }

        $voucherUnique = $promoId
            ? Rule::unique('promos', 'voucher_code')->ignore($promoId)
            : Rule::unique('promos', 'voucher_code');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in([
                Promo::TYPE_PERIODE,
                Promo::TYPE_PER_ITEM,
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
            // Tipe 3 voucher param
            'voucher_code' => ['nullable', 'string', 'max:32',
                'regex:/^[A-Z0-9_\-]+$/', $voucherUnique],
            // Tipe 4 bundling params
            'bundle_rules' => ['nullable', 'array'],
            'bundle_rules.*.product_id' => ['required_with:bundle_rules', 'integer', 'exists:products,id'],
            'bundle_rules.*.qty' => ['required_with:bundle_rules', 'numeric', 'min:0.0001'],
            // Tipe 5 tebus_murah params
            'tebus_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'tebus_price' => ['nullable', 'numeric', 'min:0'],
            'qualifying_product_ids' => ['nullable', 'array'],
            'qualifying_product_ids.*' => ['integer', 'exists:products,id'],
            'qualifying_category_ids' => ['nullable', 'array'],
            'qualifying_category_ids.*' => ['integer', 'exists:categories,id'],
            'qualifying_min_qty_per_set' => ['nullable', 'integer', 'min:1'],
            'max_tebus_per_transaction' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            'is_stackable' => ['nullable', 'boolean'],
        ]);

        // Tipe-specific validasi
        if ($data['type'] === Promo::TYPE_PER_ITEM) {
            $hasProducts = ! empty($data['product_ids']);
            $hasCategories = ! empty($data['category_ids']);
            if (! $hasProducts && ! $hasCategories) {
                abort(422, 'Tipe Per-Barang wajib pilih minimal 1 produk atau 1 kategori.');
            }
        }

        if ($data['type'] === Promo::TYPE_VOUCHER) {
            if (empty($data['voucher_code'])) {
                abort(422, 'Tipe Voucher wajib mengisi Kode Voucher.');
            }
        }

        if ($data['type'] === Promo::TYPE_BUNDLING) {
            $rules = $data['bundle_rules'] ?? [];
            if (count($rules) < 2) {
                abort(422, 'Tipe Bundling wajib min 2 komponen produk (untuk 1 produk pakai Per-Barang).');
            }
            // Unique product_id dalam bundle (1 produk hanya boleh 1 baris)
            $pids = array_map(fn ($r) => (int) ($r['product_id'] ?? 0), $rules);
            if (count($pids) !== count(array_unique($pids))) {
                abort(422, 'Produk di Bundle harus unik (1 produk 1 baris, gabung qty kalau perlu).');
            }
        }

        if ($data['type'] === Promo::TYPE_TEBUS_MURAH) {
            if (empty($data['tebus_product_id'])) {
                abort(422, 'Tipe Tebus Murah wajib pilih produk tebus.');
            }
            if (! isset($data['tebus_price']) || (float) $data['tebus_price'] < 0) {
                abort(422, 'Tipe Tebus Murah wajib isi harga tebus (≥ 0).');
            }
            // Tebus product tidak boleh sama dengan qualifying product (anti-self-discount loop)
            $tebusPid = (int) $data['tebus_product_id'];
            $qualifyingPids = array_map('intval', $data['qualifying_product_ids'] ?? []);
            if (in_array($tebusPid, $qualifyingPids, true)) {
                abort(422, 'Produk tebus tidak boleh sama dengan produk syarat (akan double-count diri sendiri).');
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
        switch ($data['type']) {
            case Promo::TYPE_PER_ITEM:
                return [
                    'product_ids' => array_values(array_unique(array_map('intval', $data['product_ids'] ?? []))),
                    'category_ids' => array_values(array_unique(array_map('intval', $data['category_ids'] ?? []))),
                ];

            case Promo::TYPE_BUNDLING:
                $rules = [];
                foreach ($data['bundle_rules'] ?? [] as $r) {
                    $rules[] = [
                        'product_id' => (int) $r['product_id'],
                        'qty' => (float) $r['qty'],
                    ];
                }

                return ['bundle_rules' => $rules];

            case Promo::TYPE_TEBUS_MURAH:
                return [
                    'qualifying_product_ids' => array_values(array_unique(array_map('intval', $data['qualifying_product_ids'] ?? []))),
                    'qualifying_category_ids' => array_values(array_unique(array_map('intval', $data['qualifying_category_ids'] ?? []))),
                    'qualifying_min_qty_per_set' => max(1, (int) ($data['qualifying_min_qty_per_set'] ?? 1)),
                    'tebus_product_id' => (int) $data['tebus_product_id'],
                    'tebus_price' => (float) $data['tebus_price'],
                    'max_tebus_per_transaction' => isset($data['max_tebus_per_transaction']) && $data['max_tebus_per_transaction'] !== null
                        ? (int) $data['max_tebus_per_transaction']
                        : null,
                ];

            default:
                return null;
        }
    }
}
