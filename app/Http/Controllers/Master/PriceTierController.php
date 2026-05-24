<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Tenant\PriceTier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * CRUD tier harga (Eceran/Grosir/Klinik/dll). Tier default (is_default=true)
 * jadi anchor fallback — tidak boleh dihapus, hanya bisa di-rename atau
 * dipindahkan ke tier lain via swap.
 */
class PriceTierController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('master.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        PriceTier::create([
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? 99,
            'is_default' => false,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return back()->with('success', "Tier '{$data['name']}' ditambahkan.");
    }

    public function update(Request $request, PriceTier $tier): RedirectResponse
    {
        $this->authorize('master.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $tier->update([
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? $tier->sort_order,
            'is_active' => $data['is_active'] ?? $tier->is_active,
        ]);

        return back()->with('success', "Tier '{$tier->name}' diperbarui.");
    }

    public function destroy(PriceTier $tier): RedirectResponse
    {
        $this->authorize('master.manage');

        if ($tier->is_default) {
            abort(422, 'Tier default tidak bisa dihapus. Set tier lain sebagai default dulu.');
        }

        $tier->delete(); // cascade ke product_unit_prices

        return back()->with('success', "Tier '{$tier->name}' dihapus.");
    }

    /**
     * Atomic swap: set tier lain sebagai default, demote current default.
     */
    public function setDefault(PriceTier $tier): RedirectResponse
    {
        $this->authorize('master.manage');

        if ($tier->is_default) {
            return back()->with('success', "'{$tier->name}' sudah default.");
        }

        \DB::transaction(function () use ($tier) {
            PriceTier::where('is_default', true)->update(['is_default' => false]);
            $tier->update(['is_default' => true]);
        });

        Cache::driver('array')->forget('price_tier:default_id');

        return back()->with('success', "Tier default diubah ke '{$tier->name}'.");
    }
}
