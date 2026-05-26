<?php

use App\Http\Controllers\Reports\CashBankReportController;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Shift;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cash/Bank Reports (Batch A).
 *   - Mutasi akun kas/bank: D=masuk, C=keluar, running balance.
 *   - Shift kasir: list with variance.
 *   - Permission gating.
 */

function ownerForCbRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForCbRep(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier CbRep', 'email' => 'cashier-cbrep@test.local',
            'password' => bcrypt('test'), 'is_active' => true,
            'warehouse_id' => Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function callCbRep(string $method, array $params = [])
{
    $controller = app(CashBankReportController::class);
    $req = Request::create('/reports/cash-bank', 'GET', $params);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->{$method}($req);
}

function postCbJournal(string $description, string $date, array $lines): Journal
{
    $journal = Journal::create([
        'journal_no' => 'CBREP-'.uniqid('', true),
        'date' => $date,
        'description' => 'CBREP-TEST-'.$description,
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => Auth::id(),
    ]);
    foreach ($lines as $l) {
        $coaId = Coa::where('code', $l[0])->value('id');
        JournalEntry::create([
            'journal_id' => $journal->id,
            'coa_id' => $coaId,
            'debit' => $l[1],
            'credit' => $l[2],
        ]);
    }

    return $journal;
}

function cleanupCbRep(): void
{
    $ids = Journal::where('description', 'like', 'CBREP-TEST-%')->pluck('id');
    JournalEntry::whereIn('journal_id', $ids)->delete();
    Journal::whereIn('id', $ids)->delete();
    Shift::query()->where('id', '<', 0)->delete(); // no-op; shift cleanup individual per test
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForCbRep());
    cleanupCbRep();
});

afterEach(function () {
    cleanupCbRep();
});

it('MUTASI: D 1101 = masuk, C 1101 = keluar, saldo running konsisten', function () {
    $kasId = Coa::where('code', '1101')->value('id');

    // Snapshot baseline opening (akun 1101 mungkin ada activity dari demo data).
    $before = callCbRep('index', [
        'from' => '2027-12-01', 'to' => '2027-12-31', 'coa_id' => $kasId,
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props']['totals'];

    // Post 3 jurnal balanced, periode mix opening + in-period.
    postCbJournal('opening-50k', '2026-12-20', [
        ['1101', 50000, 0],
        ['3101', 0, 50000],
    ]);
    postCbJournal('in-100k', '2027-12-05', [
        ['1101', 100000, 0],
        ['4101', 0, 100000],
    ]);
    postCbJournal('out-30k', '2027-12-10', [
        ['1101', 0, 30000],
        ['2101', 30000, 0],
    ]);

    $after = callCbRep('index', [
        'from' => '2027-12-01', 'to' => '2027-12-31', 'coa_id' => $kasId,
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props']['totals'];

    // Opening bertambah 50k (opening jurnal pre-periode).
    expect(round($after['opening'] - $before['opening'], 2))->toBe(50000.0);
    // Masuk bertambah 100k (in-period D 100k).
    expect(round($after['in'] - $before['in'], 2))->toBe(100000.0);
    // Keluar bertambah 30k (in-period C 30k).
    expect(round($after['out'] - $before['out'], 2))->toBe(30000.0);
    // Closing = opening + in − out
    expect(round($after['closing'] - $before['closing'], 2))->toBe(120000.0);
    // Sanity: closing = opening + in - out konsisten antar before & after
    expect(round($after['closing'] - $after['opening'] - $after['in'] + $after['out'], 2))->toBe(0.0);
});

it('SHIFTS: list shifts dalam periode dengan variance', function () {
    $owner = ownerForCbRep();
    $wh = Warehouse::query()->firstOrFail();

    // Buat 2 shift di Jan 2028 — variance +5k & -3k
    Shift::create([
        'cashier_id' => $owner->id,
        'warehouse_id' => $wh->id,
        'opened_at' => '2028-01-01 08:00:00',
        'closed_at' => '2028-01-01 18:00:00',
        'opening_cash' => 100000,
        'expected_cash' => 250000,
        'closing_cash' => 255000,
        'cash_variance' => 5000,
        'status' => 'closed',
    ]);
    Shift::create([
        'cashier_id' => $owner->id,
        'warehouse_id' => $wh->id,
        'opened_at' => '2028-01-02 08:00:00',
        'closed_at' => '2028-01-02 18:00:00',
        'opening_cash' => 100000,
        'expected_cash' => 200000,
        'closing_cash' => 197000,
        'cash_variance' => -3000,
        'status' => 'closed',
    ]);

    $props = callCbRep('shifts', [
        'from' => '2028-01-01',
        'to' => '2028-01-31',
    ])->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    // Filter only ones from THIS test (variances 5000 & -3000 unique)
    $mine = collect($props['rows'])->filter(
        fn ($r) => in_array($r->cash_variance, [5000.0, -3000.0], true),
    );
    expect($mine->count())->toBeGreaterThanOrEqual(2);

    // Cleanup shifts dibuat di sini
    Shift::whereBetween('opened_at', ['2028-01-01', '2028-01-31'])
        ->where('cashier_id', $owner->id)->delete();
});

it('PERM: cashier → 403 untuk kedua endpoint', function () {
    Auth::login(cashierForCbRep());
    expect(fn () => callCbRep('index'))->toThrow(AuthorizationException::class);
    expect(fn () => callCbRep('shifts'))->toThrow(AuthorizationException::class);
});

it('EXPORT: cash-bank export & shifts export → xlsx', function () {
    $kasId = Coa::where('code', '1101')->value('id');
    postCbJournal('exp', '2028-02-10', [
        ['1101', 1000, 0],
        ['4101', 0, 1000],
    ]);

    $r1 = callCbRep('index', ['from' => '2028-02-01', 'to' => '2028-02-28', 'coa_id' => $kasId, 'export' => '1']);
    $r2 = callCbRep('shifts', ['from' => '2028-02-01', 'to' => '2028-02-28', 'export' => '1']);

    expect($r1)->toBeInstanceOf(BinaryFileResponse::class);
    expect($r2)->toBeInstanceOf(BinaryFileResponse::class);
});
