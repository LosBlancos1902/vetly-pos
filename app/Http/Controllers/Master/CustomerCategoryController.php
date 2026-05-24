<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Customer;
use App\Models\Tenant\CustomerCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master kategori pelanggan CRM. Hierarki support via parent_id (Member
 * > VIP / Member > Reguler). Visual cue: color (shadcn variant) + icon
 * (emoji 1-3 char).
 *
 * Destroy 3-tier (consistent dgn pattern Category/Supplier):
 *   - Ada sub → tolak (cegah orphan parent_id)
 *   - Ada customer → soft-deactivate (jaga histori CRM)
 *   - Bersih → hard delete
 *
 * Permission: customer.manage (existing — owner/manager/cashier dpt).
 */
class CustomerCategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('customer.manage');

        $cats = CustomerCategory::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->withCount('children')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        // Customer count per category — di luar pagination (FK ada di customers.customer_category_id)
        $catIds = $cats->pluck('id')->all();
        $customerCounts = Customer::query()
            ->whereIn('customer_category_id', $catIds)
            ->selectRaw('customer_category_id, COUNT(*) as cnt')
            ->groupBy('customer_category_id')
            ->pluck('cnt', 'customer_category_id');

        $allCats = CustomerCategory::all(['id', 'name'])->keyBy('id');
        $cats->getCollection()->transform(function ($c) use ($customerCounts, $allCats) {
            $c->customer_count = (int) ($customerCounts[$c->id] ?? 0);
            $c->parent_name = $c->parent_id ? ($allCats[$c->parent_id]->name ?? null) : null;

            return $c;
        });

        return Inertia::render('Master/CustomerCategories', [
            'categories' => $cats,
            'parentOptions' => CustomerCategory::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id']),
            'colors' => CustomerCategory::VALID_COLORS,
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('customer.manage');

        $data = $this->validateCategory($request);

        CustomerCategory::create($data);

        return back()->with('success', "Kategori '{$data['name']}' ditambahkan.");
    }

    public function update(Request $request, CustomerCategory $category): RedirectResponse
    {
        $this->authorize('customer.manage');

        $data = $this->validateCategory($request, $category->id);

        if (! empty($data['parent_id'])) {
            if ((int) $data['parent_id'] === $category->id) {
                abort(422, 'Parent tidak boleh diri sendiri.');
            }
            if ($this->isDescendant($category->id, (int) $data['parent_id'])) {
                abort(422, 'Parent tidak boleh kategori turunannya sendiri (loop).');
            }
        }

        $category->update($data);

        return back()->with('success', "Kategori '{$category->name}' diperbarui.");
    }

    public function destroy(CustomerCategory $category): RedirectResponse
    {
        $this->authorize('customer.manage');

        if ($category->children()->exists()) {
            abort(422, 'Kategori ini punya sub-kategori. Hapus/pindahkan dulu sub-kategorinya.');
        }

        if (Customer::where('customer_category_id', $category->id)->exists()) {
            $category->update(['is_active' => false]);

            return back()->with('success', "Kategori '{$category->name}' dinonaktifkan (ada pelanggan terkait).");
        }

        $category->delete();

        return back()->with('success', "Kategori '{$category->name}' dihapus.");
    }

    private function validateCategory(Request $request, ?int $categoryId = null): array
    {
        $nameRule = $categoryId
            ? ['required', 'string', 'max:120', Rule::unique('customer_categories', 'name')->ignore($categoryId)]
            : ['required', 'string', 'max:120', 'unique:customer_categories,name'];

        return $request->validate([
            'name' => $nameRule,
            'parent_id' => ['nullable', 'integer', 'exists:customer_categories,id'],
            'color' => ['nullable', Rule::in(CustomerCategory::VALID_COLORS)],
            'icon' => ['nullable', 'string', 'max:8'],
            'is_active' => ['boolean'],
        ]);
    }

    private function isDescendant(int $categoryId, int $candidateParentId): bool
    {
        $cursor = CustomerCategory::find($candidateParentId);
        $guard = 50;
        while ($cursor && $guard-- > 0) {
            if ((int) $cursor->parent_id === $categoryId) {
                return true;
            }
            $cursor = $cursor->parent_id ? CustomerCategory::find($cursor->parent_id) : null;
        }

        return false;
    }
}
