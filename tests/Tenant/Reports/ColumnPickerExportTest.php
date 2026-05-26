<?php

use App\Http\Controllers\Reports\FinancialReportController;
use App\Http\Controllers\Reports\InventoryReportController;
use App\Http\Controllers\Reports\PurchasingReportController;
use App\Http\Controllers\Reports\SalesReportController;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Journal;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Sale;
use App\Models\Tenant\SaleItem;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use App\Services\Reports\ColumnPicker;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Test column picker behavior:
 *   - Default columns dipakai kalau ?columns[] tidak di-pass.
 *   - Selected columns difilter & urut sesuai definisi BE (anti-tamper).
 *   - Excel header & data sesuai pilihan.
 *   - Inertia render include `available_columns` prop dgn struktur benar.
 */

function ownerCp(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function callExport($controller, string $method, array $params)
{
    $req = Request::create('/reports/test', 'GET', $params);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->{$method}($req);
}

/**
 * Parse first sheet headers (row 1) dari file xlsx response.
 * PhpSpreadsheet 5.x: getCellByColumnAndRow() dihapus → pakai coordinate string.
 */
function xlsxFirstSheetHeaders(BinaryFileResponse $resp): array
{
    $path = $resp->getFile()->getPathname();
    $spreadsheet = IOFactory::load($path);
    $sheet = $spreadsheet->getSheet(0);
    $row1 = $sheet->rangeToArray('A1:'.$sheet->getHighestColumn().'1', null, false, false, false)[0] ?? [];

    // Trim trailing empties
    while (! empty($row1) && ($row1[count($row1) - 1] === null || $row1[count($row1) - 1] === '')) {
        array_pop($row1);
    }

    return array_map(fn ($v) => (string) $v, $row1);
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerCp());

    // Cleanup CP-specific data
    $ids = Journal::where('description', 'like', 'CPTEST-%')->pluck('id');
    JournalEntry::whereIn('journal_id', $ids)->delete();
    Journal::whereIn('id', $ids)->delete();
    Sale::where('invoice_no', 'like', 'CPTEST-%')->delete();
});

afterEach(function () {
    $ids = Journal::where('description', 'like', 'CPTEST-%')->pluck('id');
    JournalEntry::whereIn('journal_id', $ids)->delete();
    Journal::whereIn('id', $ids)->delete();
    Sale::where('invoice_no', 'like', 'CPTEST-%')->delete();
});

// ─── HELPER UNIT ────────────────────────────────────────────────

it('ColumnPicker::pick fallback ke default kolom kalau selected null/empty', function () {
    $cols = [
        'a' => ['label' => 'A', 'default' => true, 'value' => fn ($r) => $r->a],
        'b' => ['label' => 'B', 'default' => false, 'value' => fn ($r) => $r->b],
        'c' => ['label' => 'C', 'default' => true, 'value' => fn ($r) => $r->c],
    ];

    [$labels, $extractors] = ColumnPicker::pick($cols, null);
    expect($labels)->toBe(['A', 'C']); // hanya default=true
    expect(count($extractors))->toBe(2);

    [$labels2] = ColumnPicker::pick($cols, []);
    expect($labels2)->toBe(['A', 'C']); // empty array juga fallback
});

it('ColumnPicker::pick whitelist key invalid (anti-tamper)', function () {
    $cols = [
        'name' => ['label' => 'Name', 'default' => true, 'value' => fn ($r) => $r->name],
        'price' => ['label' => 'Price', 'default' => true, 'value' => fn ($r) => $r->price],
    ];

    // Mixed valid + invalid (SQL injection, fake key) → hanya valid yang dipakai
    [$labels] = ColumnPicker::pick($cols, ['name', "'; DROP TABLE--", 'fake_key', 'price']);
    expect($labels)->toBe(['Name', 'Price']);

    // Semua invalid → fallback default
    [$labels2] = ColumnPicker::pick($cols, ['evil', 'unknown']);
    expect($labels2)->toBe(['Name', 'Price']);
});

it('ColumnPicker::pick selected order MENGIKUTI definisi BE, bukan urutan klien', function () {
    $cols = [
        'a' => ['label' => 'A', 'default' => false, 'value' => fn ($r) => 1],
        'b' => ['label' => 'B', 'default' => false, 'value' => fn ($r) => 2],
        'c' => ['label' => 'C', 'default' => false, 'value' => fn ($r) => 3],
    ];

    // Klien kirim urutan terbalik
    [$labels] = ColumnPicker::pick($cols, ['c', 'a']);
    // Tapi output urutan = urutan klien (filter)
    expect($labels)->toBe(['C', 'A']);
});

it('ColumnPicker::publicMeta tidak expose value extractor', function () {
    $cols = [
        'a' => ['label' => 'A', 'default' => true, 'value' => fn ($r) => 'secret'],
    ];
    $meta = ColumnPicker::publicMeta($cols);
    expect($meta)->toBe([['key' => 'a', 'label' => 'A', 'default' => true]]);
    expect(array_keys($meta[0]))->toBe(['key', 'label', 'default']);
});

// ─── INTEGRATION: P&L export ────────────────────────────────────────────────

it('EXPORT P&L: default columns kalau ?columns[] tidak di-pass', function () {
    $kasId = Coa::where('code', '1101')->value('id');
    $revId = Coa::where('code', '4101')->value('id');
    $j = Journal::create([
        'journal_no' => 'CPTEST-PL-'.uniqid(),
        'date' => '2029-01-15',
        'description' => 'CPTEST-pl',
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => Auth::id(),
    ]);
    JournalEntry::create(['journal_id' => $j->id, 'coa_id' => $kasId, 'debit' => 10000, 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'coa_id' => $revId, 'debit' => 0, 'credit' => 10000]);

    $resp = callExport(
        app(FinancialReportController::class),
        'profitLoss',
        ['from' => '2029-01-01', 'to' => '2029-01-31', 'export' => '1'],
    );
    $headers = xlsxFirstSheetHeaders($resp);

    // Default columns dari columnsPl(): code, name, type, amount
    expect($headers)->toBe(['Kode Akun', 'Nama Akun', 'Tipe', 'Nilai']);
});

it('EXPORT P&L: kolom dipilih → Excel header hanya kolom itu', function () {
    $kasId = Coa::where('code', '1101')->value('id');
    $revId = Coa::where('code', '4101')->value('id');
    $j = Journal::create([
        'journal_no' => 'CPTEST-PL2-'.uniqid(),
        'date' => '2029-02-15',
        'description' => 'CPTEST-pl2',
        'status' => 'posted',
        'posted_at' => now(),
        'posted_by' => Auth::id(),
    ]);
    JournalEntry::create(['journal_id' => $j->id, 'coa_id' => $kasId, 'debit' => 10000, 'credit' => 0]);
    JournalEntry::create(['journal_id' => $j->id, 'coa_id' => $revId, 'debit' => 0, 'credit' => 10000]);

    $resp = callExport(
        app(FinancialReportController::class),
        'profitLoss',
        [
            'from' => '2029-02-01', 'to' => '2029-02-28', 'export' => '1',
            'columns' => ['code', 'total_debit', 'total_credit'],
        ],
    );
    $headers = xlsxFirstSheetHeaders($resp);
    expect($headers)->toBe(['Kode Akun', 'Total Debit', 'Total Kredit']);
});

it('EXPORT P&L: kolom invalid diabaikan, fallback default', function () {
    $resp = callExport(
        app(FinancialReportController::class),
        'profitLoss',
        [
            'from' => '2029-03-01', 'to' => '2029-03-31', 'export' => '1',
            'columns' => ['evil_key', 'sql_injection'],
        ],
    );
    $headers = xlsxFirstSheetHeaders($resp);
    expect($headers)->toBe(['Kode Akun', 'Nama Akun', 'Tipe', 'Nilai']);
});

// ─── INTEGRATION: Sales DETAIL export ────────────────────────────────────────

it('EXPORT Sales detail: kolom default sesuai spec (invoice/tanggal/cabang/kasir/pelanggan/produk/qty/harga/subtotal/total/metode)', function () {
    $resp = callExport(
        app(SalesReportController::class),
        'index',
        [
            'dim' => 'produk',
            'from' => '2029-04-01', 'to' => '2029-04-30',
            'export' => '1',
        ],
    );
    $headers = xlsxFirstSheetHeaders($resp);

    // columnsSalesDetail() default=true: invoice_no, date, warehouse_name,
    // cashier_name, customer_name, sku, product_name, qty, price,
    // item_subtotal, sale_total, payment_method
    expect($headers)->toContain('No Invoice', 'Tanggal', 'Cabang', 'Kasir',
        'Pelanggan', 'SKU', 'Produk', 'Qty (base)', 'Harga Satuan',
        'Subtotal Item', 'Total Sale', 'Metode Bayar');
});

it('EXPORT Sales detail: pilih subset minimal (invoice, total)', function () {
    $resp = callExport(
        app(SalesReportController::class),
        'index',
        [
            'dim' => 'produk',
            'from' => '2029-05-01', 'to' => '2029-05-31',
            'export' => '1',
            'columns' => ['invoice_no', 'sale_total'],
        ],
    );
    $headers = xlsxFirstSheetHeaders($resp);
    expect($headers)->toBe(['No Invoice', 'Total Sale']);
});

// ─── INTEGRATION: AP Aging export ────────────────────────────────────────────

it('EXPORT AP Aging: columns spec (ap_no, supplier, jatuh tempo, sisa, overdue, bucket) default', function () {
    $resp = callExport(
        app(PurchasingReportController::class),
        'apAging',
        ['as_of' => '2029-06-15', 'export' => '1'],
    );
    $headers = xlsxFirstSheetHeaders($resp);

    // columnsApAging defaults: ap_no, supplier_name, due_date, amount,
    // remaining, days_overdue, bucket
    expect($headers)->toContain('No AP', 'Supplier', 'Jatuh Tempo',
        'Nilai', 'Sisa', 'Hari Overdue (+ = overdue)', 'Bucket');
});

// ─── INTEGRATION: Buku Besar export ────────────────────────────────────────

it('EXPORT Buku Besar: kolom default (tanggal/no jurnal/keterangan/debit/kredit/saldo)', function () {
    $kasId = Coa::where('code', '1101')->value('id');

    $resp = callExport(
        app(FinancialReportController::class),
        'generalLedger',
        ['from' => '2029-07-01', 'to' => '2029-07-31', 'coa_id' => $kasId, 'export' => '1'],
    );
    $headers = xlsxFirstSheetHeaders($resp);
    expect($headers)->toBe(['Tanggal', 'No Jurnal', 'Keterangan', 'Debit', 'Kredit', 'Saldo Berjalan']);
});

// ─── INERTIA PROP: available_columns dikirim ke FE ───────────────────────────

it('PROP available_columns: dikirim di P&L Inertia render dengan struktur key+label+default', function () {
    $controller = app(FinancialReportController::class);
    $req = Request::create('/reports/profit-loss', 'GET', []);
    $req->setUserResolver(fn () => Auth::user());

    $props = $controller->profitLoss($req)
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props['available_columns'])->toBeArray();
    expect($props['available_columns'][0])->toHaveKeys(['key', 'label', 'default']);
    expect($props['available_columns'][0]['key'])->toBe('code');
    expect($props['available_columns'][0]['default'])->toBeTrue();
});

it('PROP available_columns: dikirim di SALES, AP Aging, Inventory, CashBank', function () {
    $reqMaker = function (string $path, array $p = []) {
        $r = Request::create($path, 'GET', $p);
        $r->setUserResolver(fn () => Auth::user());

        return $r;
    };

    foreach ([
        [app(SalesReportController::class), 'index', '/reports/sales'],
        [app(PurchasingReportController::class), 'apAging', '/reports/purchasing/ap-aging'],
        [app(InventoryReportController::class), 'valuation', '/reports/inventory/valuation'],
    ] as [$ctrl, $method, $path]) {
        $props = $ctrl->{$method}($reqMaker($path))
            ->toResponse(request())->getOriginalContent()->getData()['page']['props'];
        expect($props)->toHaveKey('available_columns');
        expect($props['available_columns'])->toBeArray();
        expect(count($props['available_columns']))->toBeGreaterThan(0);
    }
});
