<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master pelanggan. Struktur kompatibel Vetly Klinik via vetly_customer_id
 * (existing field) + phone sebagai identifier utama.
 *
 * Permission: customer.manage (owner/manager/cashier/apoteker/supervisor
 * dapet by default — common kasir workflow utk quick-create dari POS).
 *
 * Destroy 3-tier (consistent dgn Categories/Suppliers):
 *   - Bersih (no sales) → hard delete
 *   - Pernah transaksi → soft-deactivate (is_active=false)
 */
class CustomerController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('customer.manage');

        $customers = Customer::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")))
            ->when($request->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($request->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->withCount('sales')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Master/Customers', [
            'customers' => $customers,
            'filters' => $request->only('search', 'status'),
        ]);
    }

    /**
     * Live search JSON untuk CustomerPicker di POS. Limit 20, prefer
     * match by phone (identifier utama untuk Klinik sync).
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('customer.manage');

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
        $results = Customer::query()
            ->where('is_active', true)
            ->where(function ($w) use ($like) {
                $w->where('phone', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('code', 'like', $like);
            })
            // Phone match first (identifier utama)
            ->orderByRaw('CASE WHEN phone LIKE ? THEN 0 ELSE 1 END', [$like])
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'code', 'name', 'phone', 'email']);

        return response()->json(['results' => $results]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('customer.manage');

        $data = $this->validateCustomer($request);

        $data['code'] = Customer::generateCode();
        $data['is_active'] = true;

        $customer = Customer::create($data);

        return back()->with('success', "Pelanggan '{$customer->name}' ditambahkan (kode {$customer->code}).");
    }

    /**
     * JSON quick-create dari POS — return customer object langsung supaya
     * frontend bisa langsung pakai tanpa reload.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $this->authorize('customer.manage');

        $data = $this->validateCustomer($request);
        $data['code'] = Customer::generateCode();
        $data['is_active'] = true;

        $customer = Customer::create($data);

        return response()->json(['customer' => $customer]);
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('customer.manage');

        $data = $this->validateCustomer($request, $customer->id);

        $customer->update($data);

        return back()->with('success', "Pelanggan '{$customer->name}' diperbarui.");
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('customer.manage');

        // Pernah transaksi → soft-deactivate (jaga histori sale).
        if (Sale::where('customer_id', $customer->id)->exists()) {
            $customer->update(['is_active' => false]);

            return back()->with('success', "Pelanggan '{$customer->name}' dinonaktifkan (ada histori).");
        }

        $customer->delete();

        return back()->with('success', "Pelanggan '{$customer->name}' dihapus.");
    }

    private function validateCustomer(Request $request, ?int $customerId = null): array
    {
        // Phone WAJIB di form (identifier utama Klinik). Unique constraint
        // sudah ada di schema; ignore self saat update.
        $phoneRule = $customerId
            ? ['required', 'string', 'max:32', Rule::unique('customers', 'phone')->ignore($customerId)]
            : ['required', 'string', 'max:32', 'unique:customers,phone'];

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => $phoneRule,
            'email' => ['nullable', 'email', 'max:255'],
            'birthday' => ['nullable', 'date'],
            'address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
