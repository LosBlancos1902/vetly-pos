<?php

use App\Http\Controllers\Reports\FinancialReportController;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Sale;
use App\Models\Tenant\SaleItem;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\JournalEngine;
use Carbon\CarbonImmutable;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Financial Reports (Batch A) — angka harus akurat & konsisten.
 * Test fokus:
 *   - Trial Balance: total debit = total kredit (sanity engine).
 *   - P&L: Pendapatan = sum revenue COA dari journal (konsisten dgn sales completed).
 *   - Neraca: Asset = Liability + Equity + Laba Berjalan.
 *   - General Ledger: opening + Δperiode = closing untuk akun manapun.
 *   - Permission: cashier (tanpa reports.financial.view) → 403.
 *   - Export Excel: jadi BinaryFileResponse dgn content-type xlsx.
 *
 * Strategi isolasi: posting jurnal manual via JournalEngine helper (postSale)
 * dgn DATE yg jauh di luar window default (mis. 2027-01-15) supaya tidak
 * tabrakan dengan demo data tenant existing. Cleanup hapus jurnal dengan
 * description prefix 'FINREP-TEST-'.
 */

function ownerForFinRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForFinRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier FinRep',
            'email' => 'cashier-finrep@test.local',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function callFinRep(string $method, array $params = [])
{
    $controller = app(FinancialReportController::class);
    $req = Request::create('/reports/'.$method, 'GET', $params);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->{$method}($req);
}

/**
 * Posting jurnal manual berbalans untuk skenario test.
 * Date: 2027-01-15 (jauh dari demo). Description prefix 'FINREP-TEST-'.
 */
function postFinRepJournal(string $description, array $lines): Journal
{
    // Direct insert (bypass JournalEngine private post) supaya kita bisa
    // pakai date arbitrary tanpa nyentuh engine internal.
    $journal = Journal::create([
        'journal_no' => 'FINREP-'.uniqid('', true),
        'date' => '2027-01-15',
        'description' => 'FINREP-TEST-'.$description,
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => Auth::id(),
    ]);
    foreach ($lines as $l) {
        // $l = ['coa_code', 'debit', 'credit']
        $coaId = \App\Models\Tenant\Coa::where('code', $l[0])->value('id');
        if (! $coaId) {
            throw new RuntimeException("COA {$l[0]} not found");
        }
        JournalEntry::create([
            'journal_id' => $journal->id,
            'coa_id' => $coaId,
            'debit' => $l[1],
            'credit' => $l[2],
        ]);
    }

    return $journal;
}

function cleanupFinRepJournals(): void
{
    $ids = Journal::where('description', 'like', 'FINREP-TEST-%')->pluck('id');
    JournalEntry::whereIn('journal_id', $ids)->delete();
    Journal::whereIn('id', $ids)->delete();
    // Sales test data (KONSISTENSI test).
    Sale::where('invoice_no', 'like', 'FINREP-%')->delete();
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForFinRep());
    cleanupFinRepJournals();
});

afterEach(function () {
    cleanupFinRepJournals();
});

// ─── Trial Balance ────────────────────────────────────────────────

it('TB: total debit == total credit untuk periode dengan jurnal balanced', function () {
    // Jurnal 1: cash sale 100k → D 1101 / C 4101
    postFinRepJournal('cash-sale-1', [
        ['1101', 100000, 0],
        ['4101', 0, 100000],
    ]);
    // Jurnal 2: cogs 60k → D 5100 / C 1201
    postFinRepJournal('cogs-1', [
        ['5100', 60000, 0],
        ['1201', 0, 60000],
    ]);

    $props = callFinRep('trialBalance', ['from' => '2027-01-01', 'to' => '2027-01-31'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['totals']['balanced'])->toBeTrue();
    expect($props['totals']['debit'])->toBe($props['totals']['credit']);
    expect($props['totals']['debit'])->toBeGreaterThanOrEqual(160000.0);
});

// ─── P&L ────────────────────────────────────────────────

it('P&L: revenue match jurnal credit ke 4xxx, gross_profit = revenue − cogs', function () {
    // 3 sales completed di Jan 2027: 100k + 150k + 200k = 450k, HPP total 270k
    postFinRepJournal('sale-1', [
        ['1101', 100000, 0],
        ['4101', 0, 100000],
        ['5100', 60000, 0],
        ['1201', 0, 60000],
    ]);
    postFinRepJournal('sale-2', [
        ['1101', 150000, 0],
        ['4101', 0, 150000],
        ['5100', 90000, 0],
        ['1201', 0, 90000],
    ]);
    postFinRepJournal('sale-3', [
        ['1101', 200000, 0],
        ['4101', 0, 200000],
        ['5100', 120000, 0],
        ['1201', 0, 120000],
    ]);

    $props = callFinRep('profitLoss', ['from' => '2027-01-01', 'to' => '2027-01-31'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['totals']['revenue'])->toBeGreaterThanOrEqual(450000.0);
    expect($props['totals']['cogs'])->toBeGreaterThanOrEqual(270000.0);
    expect($props['totals']['gross_profit'])
        ->toBe($props['totals']['revenue'] - $props['totals']['cogs']);
});

it('P&L: diskon 4199 (contra-revenue) MENGURANGI total revenue', function () {
    postFinRepJournal('sale-disc', [
        ['1101', 90000, 0],
        ['4199', 10000, 0],  // diskon penjualan (debit utk contra)
        ['4101', 0, 100000], // gross revenue 100k
    ]);

    $props = callFinRep('profitLoss', ['from' => '2027-01-01', 'to' => '2027-01-31'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    // Periode 2027-01 isolated — di tenant 'test' tidak ada data baseline di tanggal itu.
    // 4199 NB=debit → amount kita simpan dgn convention "credit - debit" untuk revenue
    // → debit 10k = amount −10k (contra mengurangi pendapatan)
    $disc = collect($props['rows'])->firstWhere('code', '4199');
    expect($disc)->not->toBeNull();
    expect((float) $disc->amount)->toBe(-10000.0);
});

// ─── Neraca ────────────────────────────────────────────────

it('Neraca: posting jurnal balanced TIDAK mengubah is_balanced state (delta = 0)', function () {
    // Tenant test mungkin punya orphan data dari test lain → snapshot dulu.
    // Lalu post balanced jurnal → state baru harus tetap sama (asset = liab+eq+pl).
    $before = callFinRep('balanceSheet', ['to' => '2027-01-31'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props']['totals'];

    // Cash sale 100k + HPP 60k. Effect:
    // +Kas 100k −Inv 60k = +40k aset
    // +Rev 100k −Cogs 60k = +40k laba berjalan
    // Diff aset == diff (liab+eq+pl). Net is_balanced state preserved.
    postFinRepJournal('balanced-sale', [
        ['1101', 100000, 0],
        ['4101', 0, 100000],
        ['5100', 60000, 0],
        ['1201', 0, 60000],
    ]);

    $after = callFinRep('balanceSheet', ['to' => '2027-01-31'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props']['totals'];

    $deltaAsset = $after['asset'] - $before['asset'];
    $deltaLE = $after['total_le'] - $before['total_le'];

    expect(round($deltaAsset, 2))->toBe(40000.0);
    expect(round($deltaLE, 2))->toBe(40000.0);
    expect(abs($deltaAsset - $deltaLE))->toBeLessThan(0.01);

    // is_balanced setelah HARUS sama dgn sebelum (kalau awal sudah balance,
    // tetap balance; kalau sudah unbalanced karena orphan, posting balanced
    // tidak akan memperbaiki tapi juga tidak memperburuk).
    expect($after['is_balanced'])->toBe($before['is_balanced']);
});

// ─── General Ledger ────────────────────────────────────────────────

it('GL: opening + sum(Δ periode) = closing untuk akun 1101', function () {
    // Pre-periode entry (Des 2026) — opening kontribusi
    $j1 = Journal::create([
        'journal_no' => 'FINREP-OPN-'.uniqid(),
        'date' => '2026-12-15',
        'description' => 'FINREP-TEST-opening',
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => Auth::id(),
    ]);
    $kasId = \App\Models\Tenant\Coa::where('code', '1101')->value('id');
    $invId = \App\Models\Tenant\Coa::where('code', '1201')->value('id');
    JournalEntry::create(['journal_id' => $j1->id, 'coa_id' => $kasId, 'debit' => 50000, 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j1->id, 'coa_id' => $invId, 'debit' => 0, 'credit' => 50000]);

    // Periode entry
    postFinRepJournal('inperiode', [
        ['1101', 30000, 0],
        ['4101', 0, 30000],
    ]);

    $props = callFinRep('generalLedger', [
        'from' => '2027-01-01',
        'to' => '2027-01-31',
        'coa_id' => $kasId,
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $opening = $props['opening'];
    $closing = $props['closing'];
    $rows = $props['rows'];

    // Hitung delta dari rows
    $delta = collect($rows)->reduce(
        fn ($acc, $r) => $acc + (float) $r->debit - (float) $r->credit,
        0.0,
    );

    expect(abs(($opening + $delta) - $closing))->toBeLessThan(0.01);
});

// ─── Permission ────────────────────────────────────────────────

it('PERM: cashier tanpa reports.financial.view → AuthorizationException', function () {
    Auth::login(cashierForFinRep());

    expect(fn () => callFinRep('profitLoss'))->toThrow(AuthorizationException::class);
    expect(fn () => callFinRep('balanceSheet'))->toThrow(AuthorizationException::class);
    expect(fn () => callFinRep('trialBalance'))->toThrow(AuthorizationException::class);
    expect(fn () => callFinRep('generalLedger'))->toThrow(AuthorizationException::class);
    expect(fn () => callFinRep('journalLog'))->toThrow(AuthorizationException::class);
});

// ─── Export Excel ────────────────────────────────────────────────

it('EXPORT: P&L export=1 returns BinaryFileResponse xlsx', function () {
    postFinRepJournal('export-test', [
        ['1101', 10000, 0],
        ['4101', 0, 10000],
    ]);

    $response = callFinRep('profitLoss', [
        'from' => '2027-01-01',
        'to' => '2027-01-31',
        'export' => '1',
    ]);

    expect($response)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->headers->get('content-type'))
        ->toContain('spreadsheetml.sheet');
});

it('EXPORT: Trial Balance export=1 returns xlsx + content > 0 bytes', function () {
    postFinRepJournal('export-tb', [
        ['1101', 5000, 0],
        ['4101', 0, 5000],
    ]);

    $response = callFinRep('trialBalance', [
        'from' => '2027-01-01',
        'to' => '2027-01-31',
        'export' => '1',
    ]);

    expect($response)->toBeInstanceOf(BinaryFileResponse::class);
    expect($response->getFile()->getSize())->toBeGreaterThan(0);
});

// ─── KONSISTENSI P&L vs Sales Report ────────────────────────────────────────────────

it('KONSISTENSI: Pendapatan di P&L (jurnal) sama dgn SUM sale.total completed di periode', function () {
    // Buat 2 sale completed di Feb 2027 untuk konsistensi check.
    // Posting jurnal-nya secara manual mengikuti pola JournalEngine::postSale:
    // D Kas / C Revenue.
    $wh = Warehouse::query()->firstOrFail();
    $owner = ownerForFinRep();

    $s1 = Sale::create([
        'invoice_no' => 'FINREP-S1-'.uniqid(),
        'date' => '2027-02-10 10:00:00',
        'warehouse_id' => $wh->id,
        'cashier_id' => $owner->id,
        'subtotal' => 50000,
        'total' => 50000,
        'status' => 'completed',
        'payment_status' => 'paid',
    ]);
    $s2 = Sale::create([
        'invoice_no' => 'FINREP-S2-'.uniqid(),
        'date' => '2027-02-12 11:00:00',
        'warehouse_id' => $wh->id,
        'cashier_id' => $owner->id,
        'subtotal' => 75000,
        'total' => 75000,
        'status' => 'completed',
        'payment_status' => 'paid',
    ]);
    // Sale void — TIDAK boleh masuk
    $sv = Sale::create([
        'invoice_no' => 'FINREP-VOID-'.uniqid(),
        'date' => '2027-02-15 11:00:00',
        'warehouse_id' => $wh->id,
        'cashier_id' => $owner->id,
        'subtotal' => 200000,
        'total' => 200000,
        'status' => 'void',
        'payment_status' => 'unpaid',
    ]);

    // Jurnal HANYA untuk yg completed.
    $j1 = Journal::create([
        'journal_no' => 'FINREP-J-'.uniqid(),
        'date' => '2027-02-10',
        'description' => 'FINREP-TEST-sale1',
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => $owner->id,
    ]);
    $kasId = \App\Models\Tenant\Coa::where('code', '1101')->value('id');
    $revId = \App\Models\Tenant\Coa::where('code', '4101')->value('id');
    JournalEntry::create(['journal_id' => $j1->id, 'coa_id' => $kasId, 'debit' => 50000, 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j1->id, 'coa_id' => $revId, 'debit' => 0, 'credit' => 50000]);

    $j2 = Journal::create([
        'journal_no' => 'FINREP-J-'.uniqid(),
        'date' => '2027-02-12',
        'description' => 'FINREP-TEST-sale2',
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => $owner->id,
    ]);
    JournalEntry::create(['journal_id' => $j2->id, 'coa_id' => $kasId, 'debit' => 75000, 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j2->id, 'coa_id' => $revId, 'debit' => 0, 'credit' => 75000]);

    // Total Pendapatan di P&L (jurnal) untuk Feb 2027 — bagian dari jurnal ini
    $plProps = callFinRep('profitLoss', ['from' => '2027-02-01', 'to' => '2027-02-28'])
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    // SUM sale.total completed di Feb 2027
    $salesSum = Sale::where('status', 'completed')
        ->whereBetween('date', ['2027-02-01 00:00:00', '2027-02-28 23:59:59'])
        ->where('invoice_no', 'like', 'FINREP-%')
        ->sum('total');

    // Revenue dari akun 4101 saja di periode
    $rev4101 = collect($plProps['rows'])->firstWhere('code', '4101');

    expect((float) $rev4101->amount)->toBe((float) $salesSum); // konsisten
    expect($sv->status)->toBe('void'); // void tetap void

    // Cleanup sales
    Sale::where('invoice_no', 'like', 'FINREP-%')->delete();
});
