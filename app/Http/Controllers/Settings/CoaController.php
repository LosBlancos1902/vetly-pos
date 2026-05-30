<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Coa;
use App\Models\Tenant\JournalEntry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * COA Editor — kelola Chart of Accounts.
 *
 * Lock 2-lapis (lihat Coa::isLocked):
 *   - SYSTEM_ACCOUNTS (di-reference JournalEngine by-code / parent heading)
 *     → kode + type/normal_balance terkunci, akun tak bisa dihapus.
 *   - akun yang sudah punya journal_entries → idem.
 *   - akun non-system & belum dijurnal → CRUD bebas.
 *
 * normal_balance auto dari type (asset/expense/cogs=debit; lainnya=credit).
 * level auto dari parent. Tidak menyentuh JournalEngine / posting jurnal.
 */
class CoaController extends Controller
{
    private const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense', 'cogs'];

    private const DEBIT_TYPES = ['asset', 'expense', 'cogs'];

    public function index(): Response
    {
        $this->authorize('coa.view');

        $usedIds = JournalEntry::query()->select('coa_id')->distinct()->pluck('coa_id')->flip();

        $accounts = Coa::orderBy('code')->get()->map(fn (Coa $c) => [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'type' => $c->type,
            'parent_id' => $c->parent_id,
            'level' => (int) $c->level,
            'normal_balance' => $c->normal_balance,
            'is_active' => (bool) $c->is_active,
            'cash_type' => $c->cash_type,
            'bank_name' => $c->bank_name,
            'account_no' => $c->account_no,
            'is_system' => $c->isSystem(),
            'is_used' => $usedIds->has($c->id),
            'is_locked' => $c->isSystem() || $usedIds->has($c->id),
        ])->values();

        return Inertia::render('Settings/Coa', [
            'accounts' => $accounts,
            'types' => self::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('coa.manage');

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[0-9.]+$/', Rule::unique('coa', 'code')],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'parent_id' => ['nullable', 'integer', 'exists:coa,id'],
            'is_active' => ['boolean'],
            'cash_type' => ['nullable', Rule::in(['cash', 'bank'])],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:255'],
        ]);

        $parent = ! empty($data['parent_id']) ? Coa::find($data['parent_id']) : null;
        if ($parent && $parent->type !== $data['type']) {
            abort(422, "Parent harus bertipe sama ({$parent->type}).");
        }
        if (! empty($data['cash_type']) && $data['type'] !== 'asset') {
            abort(422, 'cash_type hanya untuk akun bertipe asset.');
        }

        Coa::create([
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'parent_id' => $parent?->id,
            'level' => $parent ? ((int) $parent->level + 1) : 1,
            'normal_balance' => $this->normalBalanceFor($data['type']),
            'is_active' => $data['is_active'] ?? true,
            'cash_type' => $data['cash_type'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'account_no' => $data['account_no'] ?? null,
        ]);

        return back()->with('success', "Akun {$data['code']} ditambahkan.");
    }

    public function update(Request $request, Coa $coa): RedirectResponse
    {
        $this->authorize('coa.manage');

        $locked = $coa->isLocked();

        // Lapis lock: akun sistem/terpakai → kode & type TIDAK boleh berubah.
        if ($locked) {
            if ($request->filled('code') && (string) $request->input('code') !== $coa->code) {
                abort(422, "Kode akun {$coa->code} terkunci (sistem/terpakai jurnal), tidak bisa diubah.");
            }
            if ($request->filled('type') && (string) $request->input('type') !== $coa->type) {
                abort(422, "Tipe akun {$coa->code} terkunci, tidak bisa diubah.");
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'cash_type' => ['nullable', Rule::in(['cash', 'bank'])],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_no' => ['nullable', 'string', 'max:255'],
        ]);

        $payload = [
            'name' => $data['name'],
            'is_active' => $data['is_active'] ?? $coa->is_active,
            'cash_type' => $data['cash_type'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'account_no' => $data['account_no'] ?? null,
        ];

        $effectiveType = $coa->type;

        if (! $locked) {
            $editable = $request->validate([
                'code' => ['required', 'string', 'max:32', 'regex:/^[0-9.]+$/', Rule::unique('coa', 'code')->ignore($coa->id)],
                'type' => ['required', Rule::in(self::TYPES)],
                'parent_id' => ['nullable', 'integer', 'exists:coa,id'],
            ]);

            $parent = ! empty($editable['parent_id']) ? Coa::find($editable['parent_id']) : null;
            if ($parent && $parent->id === $coa->id) {
                abort(422, 'Akun tidak bisa menjadi parent dirinya sendiri.');
            }
            if ($parent && $parent->type !== $editable['type']) {
                abort(422, "Parent harus bertipe sama ({$parent->type}).");
            }

            $effectiveType = $editable['type'];
            $payload['code'] = $editable['code'];
            $payload['type'] = $editable['type'];
            $payload['parent_id'] = $parent?->id;
            $payload['level'] = $parent ? ((int) $parent->level + 1) : 1;
            $payload['normal_balance'] = $this->normalBalanceFor($editable['type']);
        }

        if (! empty($payload['cash_type']) && $effectiveType !== 'asset') {
            abort(422, 'cash_type hanya untuk akun bertipe asset.');
        }

        $coa->update($payload);

        return back()->with('success', "Akun {$coa->code} diperbarui.");
    }

    public function destroy(Coa $coa): RedirectResponse
    {
        $this->authorize('coa.manage');

        if ($coa->isLocked()) {
            abort(422, "Akun {$coa->code} terkunci (sistem/terpakai jurnal), tidak bisa dihapus.");
        }
        if ($coa->children()->exists()) {
            abort(422, "Akun {$coa->code} punya sub-akun, hapus sub-akun dulu.");
        }

        $code = $coa->code;
        $coa->delete();

        return back()->with('success', "Akun {$code} dihapus.");
    }

    private function normalBalanceFor(string $type): string
    {
        return in_array($type, self::DEBIT_TYPES, true) ? 'debit' : 'credit';
    }
}
