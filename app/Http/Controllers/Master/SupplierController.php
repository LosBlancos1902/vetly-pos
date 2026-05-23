<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Supplier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('purchasing.supplier_manage');

        $suppliers = Supplier::query()
            ->when($request->search, fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Master/Suppliers', [
            'suppliers' => $suppliers,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('purchasing.supplier_manage');

        $data = $this->validateSupplier($request);

        Supplier::create($data);

        return back()->with('success', 'Supplier ditambahkan.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $this->authorize('purchasing.supplier_manage');

        $data = $this->validateSupplier($request, $supplier->id);

        $supplier->update($data);

        return back()->with('success', 'Supplier diperbarui.');
    }

    /**
     * Soft "delete" — supplier akan dipakai PO, jangan hard delete.
     */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        $this->authorize('purchasing.supplier_manage');

        $supplier->update(['is_active' => false]);

        return back()->with('success', "Supplier '{$supplier->name}' dinonaktifkan.");
    }

    private function validateSupplier(Request $request, ?int $supplierId = null): array
    {
        $codeRule = $supplierId
            ? ['required', 'string', 'max:64', "unique:suppliers,code,{$supplierId}"]
            : ['required', 'string', 'max:64', 'unique:suppliers,code'];

        return $request->validate([
            'code' => $codeRule,
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'npwp' => ['nullable', 'string', 'max:64'],
            'payment_term_days' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
