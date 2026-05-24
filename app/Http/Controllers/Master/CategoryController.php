<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Category;
use App\Models\Tenant\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master kategori produk. Hierarki support via parent_id (self-FK).
 *
 * Delete strategy: soft-deactivate kalau ada produk pakai kategori ini,
 * hard delete kalau tidak ada. Hindari orphan parent_id di anak kategori
 * dengan validasi "no children" sebelum hard delete.
 */
class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('master.manage');

        $categories = Category::query()
            ->when($request->search, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->withCount('children')
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        // Tambah product_count + parent_name untuk display di list — di luar
        // pagination karena withCount tidak gampang nge-join ke products
        // (FK ada di products.category_id, bukan di categories).
        $catIds = $categories->pluck('id')->all();
        $productCounts = Product::query()
            ->whereIn('category_id', $catIds)
            ->selectRaw('category_id, COUNT(*) as cnt')
            ->groupBy('category_id')
            ->pluck('cnt', 'category_id');

        $allCats = Category::all(['id', 'name'])->keyBy('id');
        $categories->getCollection()->transform(function ($c) use ($productCounts, $allCats) {
            $c->product_count = (int) ($productCounts[$c->id] ?? 0);
            $c->parent_name = $c->parent_id ? ($allCats[$c->parent_id]->name ?? null) : null;

            return $c;
        });

        return Inertia::render('Master/Categories', [
            'categories' => $categories,
            // Parent options: semua kategori aktif (untuk dropdown parent picker).
            // Self & descendants di-filter di frontend saat edit, supaya tidak
            // bikin loop.
            'parentOptions' => Category::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'parent_id']),
            'filters' => $request->only('search'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('master.manage');

        $data = $this->validateCategory($request);

        Category::create($data);

        return back()->with('success', "Kategori '{$data['name']}' ditambahkan.");
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('master.manage');

        $data = $this->validateCategory($request, $category->id);

        // Cegah loop: parent_id tidak boleh = self atau descendant.
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

    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('master.manage');

        // Cegah orphan: kalau ada children, tolak sampai user pindahkan dulu.
        if ($category->children()->exists()) {
            abort(422, 'Kategori ini punya sub-kategori. Hapus/pindahkan dulu sub-kategorinya.');
        }

        // Soft-deactivate kalau ada produk pakai (jaga histori).
        if (Product::where('category_id', $category->id)->exists()) {
            $category->update(['is_active' => false]);

            return back()->with('success', "Kategori '{$category->name}' dinonaktifkan (ada produk).");
        }

        $category->delete();

        return back()->with('success', "Kategori '{$category->name}' dihapus.");
    }

    private function validateCategory(Request $request, ?int $categoryId = null): array
    {
        $nameRule = $categoryId
            ? ['required', 'string', 'max:120', Rule::unique('categories', 'name')->ignore($categoryId)]
            : ['required', 'string', 'max:120', 'unique:categories,name'];

        return $request->validate([
            'name' => $nameRule,
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * Cek apakah $candidateParentId adalah descendant dari $categoryId
     * (untuk cegah loop saat re-parent).
     */
    private function isDescendant(int $categoryId, int $candidateParentId): bool
    {
        $cursor = Category::find($candidateParentId);
        $guard = 50; // safety: cegah infinite loop kalau data sudah corrupt
        while ($cursor && $guard-- > 0) {
            if ((int) $cursor->parent_id === $categoryId) {
                return true;
            }
            $cursor = $cursor->parent_id ? Category::find($cursor->parent_id) : null;
        }

        return false;
    }
}
