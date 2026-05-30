<?php

use App\Models\Tenant\FinanceSettings;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\User as TenantUser;
use App\Services\JournalEngine;
use Database\Seeders\CoaSeeder;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Support\Facades\Auth;

/**
 * postManualEntry generik + backward-compat post() + finance_settings.
 * Assertion nilai eksplisit per akun (kode COA verbatim).
 */
function ownerForJe(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

/**
 * Hapus jurnal yang dibuat test (penanda ref_type test-je + legacy cash_bank +
 * adjustment ADJ-TEST) supaya tidak mencemari tenant `test` (report/jurnal log).
 */
function purgeJeTestData(): void
{
    $ids = Journal::where(function ($q) {
        $q->whereIn('ref_type', ['test-je', 'cash_bank'])
            ->orWhere(function ($q2) {
                $q2->where('ref_type', 'adjustment')->where('description', 'like', '%ADJ-TEST%');
            });
    })->pluck('id')->all();

    if ($ids !== []) {
        JournalEntry::whereIn('journal_id', $ids)->delete();
        Journal::whereIn('id', $ids)->delete();
    }
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    (new CoaSeeder)->run();
    Auth::login(ownerForJe());
    purgeJeTestData();
});

afterEach(function () {
    purgeJeTestData();
    Auth::logout();
});

it('MANUAL: post balanced → jurnal + entries dgn coa_id + nilai benar', function () {
    $engine = app(JournalEngine::class);

    $journal = $engine->postManualEntry('Bayar listrik', 'test-je', null, [
        ['6104', 1000000.0, 0.0],   // D Beban Listrik
        ['1101', 0.0, 1000000.0],   // C Kas Besar
    ]);

    $entries = $journal->entries()->with('coa')->get();
    expect($entries)->toHaveCount(2);

    $d = $entries->firstWhere('coa.code', '6104');
    $cr = $entries->firstWhere('coa.code', '1101');
    expect((float) $d->debit)->toBe(1000000.0);
    expect((float) $d->credit)->toBe(0.0);
    expect((float) $cr->credit)->toBe(1000000.0);
    expect((float) $cr->debit)->toBe(0.0);
    expect($journal->status)->toBe('posted');
});

it('MANUAL: tidak balance → RuntimeException, tak ada jurnal (atomic)', function () {
    $engine = app(JournalEngine::class);
    $before = Journal::count();

    expect(fn () => $engine->postManualEntry('Pincang', 'test-je', null, [
        ['6104', 1000000.0, 0.0],
        ['1101', 0.0, 900000.0],
    ]))->toThrow(RuntimeException::class);

    expect(Journal::count())->toBe($before);
});

it('MANUAL: kode COA tidak ada → RuntimeException, tak ada jurnal', function () {
    $engine = app(JournalEngine::class);
    $before = Journal::count();

    expect(fn () => $engine->postManualEntry('Akun hantu', 'test-je', null, [
        ['9999', 5000.0, 0.0],
        ['1101', 0.0, 5000.0],
    ]))->toThrow(RuntimeException::class);

    expect(Journal::count())->toBe($before);
});

it('MANUAL: backdate → journals.date = effective_date', function () {
    $engine = app(JournalEngine::class);

    $journal = $engine->postManualEntry('Backdate', 'test-je', null, [
        ['1101', 250000.0, 0.0],
        ['3101', 0.0, 250000.0],
    ], '2026-01-15');

    expect($journal->date->format('Y-m-d'))->toBe('2026-01-15');
});

it('SETTINGS: finance_settings singleton, threshold default 5jt', function () {
    $s = FinanceSettings::singleton();
    expect($s->id)->toBe(1);
    expect((float) $s->approval_threshold)->toBe(5000000.0);

    // idempotent
    expect(FinanceSettings::singleton()->id)->toBe(1);
    expect(FinanceSettings::count())->toBe(1);
});

it('BACKCOMPAT: caller existing (postAdjustment) tetap jalan & balance', function () {
    $engine = app(JournalEngine::class);

    $journal = $engine->postAdjustment('ADJ-TEST', 50000.0, true); // plus: D 1201 / C 5100
    $entries = $journal->entries()->with('coa')->get();

    $d = $entries->firstWhere('coa.code', '1201');
    $cr = $entries->firstWhere('coa.code', '5100');
    expect((float) $d->debit)->toBe(50000.0);
    expect((float) $cr->credit)->toBe(50000.0);
});

it('ROUTE: /accounting/journal di-gate can:accounting.view', function () {
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($r) => $r->getName() === 'accounting.journal');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('can:accounting.view');
});
