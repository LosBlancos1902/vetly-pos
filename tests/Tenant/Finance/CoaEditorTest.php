<?php

use App\Http\Controllers\Settings\CoaController;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\User as TenantUser;
use App\Services\JournalEngine;
use Database\Seeders\CoaSeeder;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * COA Editor — lock 2-lapis (SYSTEM_ACCOUNTS + journal_entries) + CRUD.
 * Kode COA verbatim dari kontrak.
 */
function ownerForCoa(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForCoa(): TenantUser
{
    $u = TenantUser::firstOrCreate(
        ['email' => 'cashier-coa@test.local'],
        ['name' => 'Cashier COA', 'password' => bcrypt('x'), 'is_active' => true],
    );
    if (! $u->hasRole('cashier')) {
        $u->syncRoles(['cashier']);
    }

    return $u->fresh();
}

function coaReq(string $method, array $data = []): Request
{
    $r = Request::create('/settings/coa', $method, $data);
    $r->setUserResolver(fn () => Auth::user());

    return $r;
}

/**
 * Bersihkan akun test + jurnal yang me-reference-nya (hindari FK 1451 saat
 * delete coa, dan cegah polusi tenant `test` ke test lain — report dll).
 */
function purgeCoaTestData(): void
{
    $ids = Coa::whereIn('code', ['6106', '6107', '9988'])->pluck('id')->all();
    if ($ids !== []) {
        $jids = JournalEntry::whereIn('coa_id', $ids)->pluck('journal_id')->unique()->all();
        if ($jids !== []) {
            JournalEntry::whereIn('journal_id', $jids)->delete();
            Journal::whereIn('id', $jids)->delete();
        }
    }
    Coa::whereIn('code', ['6106', '6107', '9988'])->delete();
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    (new CoaSeeder)->run();
    Auth::login(ownerForCoa());
    purgeCoaTestData();
});

afterEach(function () {
    purgeCoaTestData();
    Auth::logout();
});

// ── SYSTEM ACCOUNT LOCK ─────────────────────────────────────────────

it('SYSTEM: kode akun 1101 tidak bisa diubah', function () {
    $coa = Coa::where('code', '1101')->firstOrFail();
    $c = app(CoaController::class);

    expect(fn () => $c->update(coaReq('PUT', [
        'name' => 'Kas Besar', 'code' => '9988', 'type' => 'asset',
    ]), $coa))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(Coa::where('code', '1101')->exists())->toBeTrue();
});

it('SYSTEM: akun 2101 tidak bisa dihapus', function () {
    $coa = Coa::where('code', '2101')->firstOrFail();
    $c = app(CoaController::class);

    expect(fn () => $c->destroy($coa))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Coa::where('code', '2101')->exists())->toBeTrue();
});

it('SYSTEM: nama akun 1103 BISA diubah, kode/type tetap', function () {
    $coa = Coa::where('code', '1103')->firstOrFail();
    $c = app(CoaController::class);

    $c->update(coaReq('PUT', ['name' => 'BCA Giro', 'is_active' => true]), $coa);

    $coa->refresh();
    expect($coa->name)->toBe('BCA Giro');
    expect($coa->code)->toBe('1103');
    expect($coa->type)->toBe('asset');
});

// ── NON-SYSTEM CRUD ─────────────────────────────────────────────────

it('NON-SYSTEM unused: full CRUD (create→edit→delete)', function () {
    $c = app(CoaController::class);

    // create
    $c->store(coaReq('POST', [
        'code' => '6106', 'name' => 'Beban ATK', 'type' => 'expense',
    ]));
    $coa = Coa::where('code', '6106')->firstOrFail();
    expect($coa->normal_balance)->toBe('debit'); // expense → debit auto

    // edit nama
    $c->update(coaReq('PUT', ['name' => 'Beban Alat Tulis', 'code' => '6106', 'type' => 'expense']), $coa);
    expect($coa->fresh()->name)->toBe('Beban Alat Tulis');

    // delete
    $c->destroy($coa->fresh());
    expect(Coa::where('code', '6106')->exists())->toBeFalse();
});

it('NON-SYSTEM used: dipakai jurnal → delete DITOLAK, rename OK', function () {
    $c = app(CoaController::class);
    $engine = app(JournalEngine::class);

    // buat akun beban baru + pakai di jurnal manual (balanced ke 1101).
    $c->store(coaReq('POST', ['code' => '6107', 'name' => 'Beban Uji', 'type' => 'expense']));
    $coa = Coa::where('code', '6107')->firstOrFail();

    $engine->postManualEntry('Uji pakai akun', 'test', null, [
        ['6107', 100000.0, 0.0],
        ['1101', 0.0, 100000.0],
    ]);

    expect($coa->isUsedInJournal())->toBeTrue();

    // delete ditolak
    expect(fn () => $c->destroy($coa->fresh()))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Coa::where('code', '6107')->exists())->toBeTrue();

    // rename tetap boleh
    $c->update(coaReq('PUT', ['name' => 'Beban Uji Pakai']), $coa->fresh());
    expect(Coa::where('code', '6107')->first()->name)->toBe('Beban Uji Pakai');
});

// ── VALIDASI ────────────────────────────────────────────────────────

it('VALIDASI: normal_balance auto dari type (liability → credit)', function () {
    $c = app(CoaController::class);
    $c->store(coaReq('POST', ['code' => '9988', 'name' => 'Hutang Lain', 'type' => 'liability']));

    expect(Coa::where('code', '9988')->first()->normal_balance)->toBe('credit');
});

it('VALIDASI: cash_type hanya untuk asset (expense + cash_type → 422)', function () {
    $c = app(CoaController::class);

    expect(fn () => $c->store(coaReq('POST', [
        'code' => '9988', 'name' => 'Salah', 'type' => 'expense', 'cash_type' => 'bank',
    ])))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

// ── PERMISSION GATE ─────────────────────────────────────────────────

it('GATE: cashier (tanpa coa.manage) → AuthorizationException', function () {
    Auth::login(cashierForCoa());
    $c = app(CoaController::class);

    expect(fn () => $c->store(coaReq('POST', ['code' => '9988', 'name' => 'X', 'type' => 'asset'])))
        ->toThrow(AuthorizationException::class);
});
