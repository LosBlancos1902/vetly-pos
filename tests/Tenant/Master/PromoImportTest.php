<?php

use App\Http\Controllers\Master\PromoImportController;
use App\Models\Tenant\Product;
use App\Models\Tenant\User as TenantUser;
use App\Services\Master\PromoExcelParser;
use App\Services\Master\PromoExcelTemplate;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Excel upload daftar produk untuk Promo Per-Barang.
 *
 * Pattern: kontrak {matched, unmatched, summary}. Partial OK — yg valid
 * dikembalikan, yg invalid di-warning per baris.
 */

function ownerForPromoImport(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForPromoImport(): TenantUser
{
    // Cashier role TIDAK punya promo.manage → harus 403
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'cashier'))->first()
        ?? TenantUser::create([
            'name' => 'Test Cashier Promo',
            'email' => 'cashier-promo@test.local',
            'password' => bcrypt('test'),
            'is_active' => true,
            'warehouse_id' => \App\Models\Tenant\Warehouse::query()->firstOrFail()->id,
        ])->assignRole('cashier');
}

function callPromoImport(string $method, ?Request $request = null)
{
    $controller = app(PromoImportController::class);
    $request ??= Request::create('/master/promos/excel-preview', 'POST');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'downloadTemplate' => $controller->downloadTemplate(app(PromoExcelTemplate::class)),
        'preview' => $controller->preview($request, app(PromoExcelParser::class)),
    };
}

/**
 * Build xlsx 2-kolom (sku, nama) di temp file. Header optional.
 */
function buildPromoXlsx(array $rows, bool $withHeader = true): string
{
    $ss = new Spreadsheet;
    $sh = $ss->getActiveSheet();
    $rowNum = 1;
    if ($withHeader) {
        $sh->setCellValue('A1', 'sku');
        $sh->setCellValue('B1', 'nama');
        $rowNum = 2;
    }
    foreach ($rows as $r) {
        $sh->setCellValue('A'.$rowNum, $r[0]);
        $sh->setCellValue('B'.$rowNum, $r[1] ?? '');
        $rowNum++;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'promo-test-').'.xlsx';
    (new Xlsx($ss))->save($tmp);

    return $tmp;
}

function uploadPromoXlsx(string $path): UploadedFile
{
    return new UploadedFile(
        $path,
        'promo-items.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true, // test mode
    );
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForPromoImport());
});

// ─── DOWNLOAD TEMPLATE ────────────────────────────────────────────

it('TEMPLATE: download returns xlsx file > 0 bytes', function () {
    $resp = callPromoImport('downloadTemplate');

    expect($resp)->toBeInstanceOf(BinaryFileResponse::class);
    expect($resp->headers->get('content-type'))->toContain('spreadsheetml.sheet');
    expect($resp->getFile()->getSize())->toBeGreaterThan(0);
});

it('TEMPLATE PERM: cashier tanpa promo.manage → AuthorizationException', function () {
    Auth::login(cashierForPromoImport());
    expect(fn () => callPromoImport('downloadTemplate'))
        ->toThrow(AuthorizationException::class);
});

// ─── PREVIEW: MATCH ────────────────────────────────────────────

it('PREVIEW: SKU valid → masuk matched dengan id+sku+name+row_excel', function () {
    // SKU-001 & SKU-002 ada di demo seed (active + sellable)
    $p1 = Product::where('sku', 'SKU-001')->firstOrFail();
    $p2 = Product::where('sku', 'SKU-002')->firstOrFail();

    $path = buildPromoXlsx([
        ['SKU-001', 'Test 1'],
        ['SKU-002', 'Test 2'],
    ]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    expect($json['summary']['matched_count'])->toBe(2);
    expect($json['summary']['unmatched_count'])->toBe(0);
    expect($json['matched'])->toHaveCount(2);
    expect($json['matched'][0]['id'])->toBe($p1->id);
    expect($json['matched'][0]['sku'])->toBe('SKU-001');
    expect($json['matched'][0]['row_excel'])->toBe(2); // baris 1 = header
    expect($json['matched'][1]['id'])->toBe($p2->id);

    @unlink($path);
});

it('PREVIEW: tanpa header (langsung data) tetap parsed correctly', function () {
    $path = buildPromoXlsx([
        ['SKU-001', ''],
    ], withHeader: false);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    expect($json['matched'])->toHaveCount(1);
    expect($json['matched'][0]['row_excel'])->toBe(1); // no header → baris 1 = data

    @unlink($path);
});

// ─── PREVIEW: UNMATCHED ────────────────────────────────────────────

it('PREVIEW: SKU tidak ketemu → masuk unmatched dengan row_excel + name_ref', function () {
    $path = buildPromoXlsx([
        ['SKU-001', 'valid'],
        ['SKU-ZZZ-NOEXIST', 'gak ada'],
        ['SKU-XXX-MISSING', 'juga gak ada'],
    ]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    expect($json['summary']['matched_count'])->toBe(1);
    expect($json['summary']['unmatched_count'])->toBe(2);
    expect($json['unmatched'])->toHaveCount(2);
    expect($json['unmatched'][0]['sku'])->toBe('SKU-ZZZ-NOEXIST');
    expect($json['unmatched'][0]['row_excel'])->toBe(3); // baris 1 header, 2 = SKU-001
    expect($json['unmatched'][0]['name_ref'])->toBe('gak ada');
    expect($json['unmatched'][1]['row_excel'])->toBe(4);

    @unlink($path);
});

// ─── PREVIEW: DEDUP ────────────────────────────────────────────

it('PREVIEW: SKU duplikat (termasuk case-insensitive) di-dedup', function () {
    $path = buildPromoXlsx([
        ['SKU-001', 'first'],
        ['sku-001', 'lowercase dup'],   // dup case-insensitive
        ['SKU-001', 'exact dup'],        // dup exact
        ['SKU-002', 'beda'],
    ]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    // SKU-001 ketiga + sku-001 = dedup 2
    expect($json['summary']['dedup_skipped'])->toBe(2);
    expect($json['summary']['matched_count'])->toBe(2); // SKU-001 + SKU-002

    @unlink($path);
});

// ─── PREVIEW: EMPTY ROW ────────────────────────────────────────────

it('PREVIEW: baris kosong di tengah di-skip', function () {
    $path = buildPromoXlsx([
        ['SKU-001', 'a'],
        ['', ''],        // empty
        ['SKU-002', 'b'],
        ['   ', ''],     // whitespace only — trim → empty
    ]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    expect($json['summary']['matched_count'])->toBe(2);
    expect($json['summary']['empty_skipped'])->toBeGreaterThanOrEqual(2);

    @unlink($path);
});

// ─── PREVIEW: PERMISSION ────────────────────────────────────────────

it('PREVIEW PERM: cashier tanpa promo.manage → AuthorizationException', function () {
    Auth::login(cashierForPromoImport());

    $path = buildPromoXlsx([['SKU-001', '']]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    expect(fn () => callPromoImport('preview', $req))
        ->toThrow(AuthorizationException::class);

    @unlink($path);
});

// ─── PREVIEW: INACTIVE PRODUK TIDAK MATCH ────────────────────────────

it('PREVIEW: produk inactive TIDAK match (konsisten dgn picker UI)', function () {
    // Buat produk khusus test, lalu non-aktifkan
    $p = Product::firstOrCreate(
        ['sku' => 'PROMOIMP-INACTIVE'],
        [
            'name' => 'Promo Import Inactive',
            'type' => 'saleable_retail',
            'base_unit_id' => \App\Models\Tenant\MasterUnit::query()->firstOrFail()->id,
            'cost_avg' => 0,
            'price' => 0,
            'is_active' => false,
            'is_sellable_directly' => true,
        ],
    );
    $p->update(['is_active' => false]);

    $path = buildPromoXlsx([
        ['PROMOIMP-INACTIVE', ''],
    ]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    expect($json['summary']['matched_count'])->toBe(0);
    expect($json['summary']['unmatched_count'])->toBe(1);
    expect($json['unmatched'][0]['sku'])->toBe('PROMOIMP-INACTIVE');

    // Cleanup
    $p->delete();
    @unlink($path);
});

// ─── PREVIEW: NON-SELLABLE TIDAK MATCH ────────────────────────────

it('PREVIEW: produk is_sellable_directly=false TIDAK match', function () {
    $p = Product::firstOrCreate(
        ['sku' => 'PROMOIMP-NONSELL'],
        [
            'name' => 'Promo Import NonSell',
            'type' => 'raw_material',
            'base_unit_id' => \App\Models\Tenant\MasterUnit::query()->firstOrFail()->id,
            'cost_avg' => 0,
            'price' => 0,
            'is_active' => true,
            'is_sellable_directly' => false,
        ],
    );
    $p->update(['is_sellable_directly' => false]);

    $path = buildPromoXlsx([
        ['PROMOIMP-NONSELL', ''],
    ]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    expect($json['summary']['matched_count'])->toBe(0);
    expect($json['summary']['unmatched_count'])->toBe(1);

    $p->delete();
    @unlink($path);
});

// ─── PREVIEW: CASE INSENSITIVE MATCH ────────────────────────────

it('PREVIEW: SKU case-insensitive → tetap match', function () {
    // Cek produk SKU-001 di-match meski input lowercase
    $path = buildPromoXlsx([
        ['sku-001', ''],
    ]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    expect($json['summary']['matched_count'])->toBe(1);
    expect($json['matched'][0]['sku'])->toBe('SKU-001'); // pakai SKU asli dari DB, bukan input

    @unlink($path);
});

// ─── PREVIEW: PARTIAL OK ────────────────────────────────────────────

it('PREVIEW: partial — yang valid TETAP dikembalikan walau ada yg invalid', function () {
    $path = buildPromoXlsx([
        ['SKU-001', 'a'],
        ['SKU-INVALID-XX1', 'gak ada'],
        ['SKU-002', 'b'],
        ['SKU-INVALID-XX2', 'gak ada juga'],
    ]);
    $req = Request::create('/master/promos/excel-preview', 'POST');
    $req->files->set('file', uploadPromoXlsx($path));

    $json = callPromoImport('preview', $req)->getData(true);

    expect($json['matched'])->toHaveCount(2);
    expect($json['unmatched'])->toHaveCount(2);
    expect(collect($json['matched'])->pluck('sku')->all())->toBe(['SKU-001', 'SKU-002']);

    @unlink($path);
});
