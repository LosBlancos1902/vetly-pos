<?php

use App\Http\Controllers\Master\ProductImportController;
use App\Models\Tenant\Category;
use App\Models\Tenant\Inventory;
use App\Models\Tenant\MasterUnit;
use App\Models\Tenant\PriceTier;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductUnit;
use App\Models\Tenant\ProductUnitPrice;
use App\Models\Tenant\User as TenantUser;
use App\Services\Master\ProductExcelExporter;
use App\Services\Master\ProductExcelParser;
use App\Services\Master\ProductImportProcessor;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function ownerForImport(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function callImportController(string $method, ?Request $request = null)
{
    $controller = app(ProductImportController::class);
    $request ??= Request::create('/master/products/import', 'GET');
    $request->setUserResolver(fn () => Auth::user());

    return match ($method) {
        'show' => $controller->show(),
        'downloadTemplate' => $controller->downloadTemplate(app(ProductExcelExporter::class)),
        'preview' => $controller->preview($request, app(ProductExcelParser::class), app(ProductImportProcessor::class)),
        'commit' => $controller->commit($request, app(ProductExcelParser::class), app(ProductImportProcessor::class)),
    };
}

/**
 * Build xlsx in-memory dengan header dinamis sesuai tier existing.
 * Returns path to temp file.
 */
function buildImportXlsx(array $rows): string
{
    $tiers = PriceTier::orderBy('sort_order')->get();
    $unitSlots = ['base', 'satuan_2', 'satuan_3', 'satuan_4', 'satuan_5'];

    $headers = ['nama', 'kategori', 'jenis', 'kode_barang', 'barcode',
        'satuan_base',
        'satuan_2', 'rasio_2',
        'satuan_3', 'rasio_3',
        'satuan_4', 'rasio_4',
        'satuan_5', 'rasio_5'];
    foreach ($tiers as $t) {
        $slug = Str::slug($t->name, '_');
        foreach ($unitSlots as $slot) {
            $headers[] = "harga_{$slug}_{$slot}";
        }
    }

    $colLetter = function (int $n): string {
        $s = '';
        while ($n > 0) {
            $r = ($n - 1) % 26;
            $s = chr(65 + $r).$s;
            $n = intdiv($n - 1, 26);
        }

        return $s;
    };

    $ss = new Spreadsheet;
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Produk');
    foreach ($headers as $i => $h) {
        $sh->setCellValue($colLetter($i + 1).'1', $h);
    }

    foreach ($rows as $rowIdx => $rowData) {
        $excelRow = $rowIdx + 2;
        foreach ($headers as $i => $h) {
            if (isset($rowData[$h]) && $rowData[$h] !== '') {
                $sh->setCellValue($colLetter($i + 1).$excelRow, $rowData[$h]);
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'import-test-').'.xlsx';
    (new Xlsx($ss))->save($tmp);

    return $tmp;
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Cache::driver('array')->forget('price_tier:default_id');
    PriceTier::query()->update(['is_default' => false]);
    PriceTier::updateOrCreate(['name' => 'Eceran'],
        ['sort_order' => 1, 'is_default' => true, 'is_active' => true]);
    PriceTier::where('name', '!=', 'Eceran')->delete();
    Product::where('sku', 'like', 'IMP-%')->each(fn ($p) => $p->delete());
    Category::firstOrCreate(['name' => 'Pakan Kucing'], ['is_active' => true]);
});

afterEach(function () {
    Product::where('sku', 'like', 'IMP-%')->each(fn ($p) => $p->delete());
    PriceTier::query()->update(['is_default' => false]);
    PriceTier::updateOrCreate(['name' => 'Eceran'],
        ['sort_order' => 1, 'is_default' => true, 'is_active' => true]);
    PriceTier::where('name', '!=', 'Eceran')->delete();
    Cache::driver('array')->forget('price_tier:default_id');
});

// ─────────────────────────────────────────────────────────────────────────

it('downloadTemplate: response xlsx valid dgn header sesuai tier existing', function () {
    Auth::login(ownerForImport());
    PriceTier::create(['name' => 'Grosir', 'sort_order' => 2, 'is_default' => false]);

    $response = callImportController('downloadTemplate');
    expect($response->headers->get('Content-Type'))->toContain('spreadsheetml');

    $tmpPath = $response->getFile()->getRealPath();
    expect(file_exists($tmpPath))->toBeTrue();

    $loaded = IOFactory::load($tmpPath);
    $sheet = $loaded->getSheetByName('Produk');
    $headerRow = $sheet->toArray(null, true, true, false)[0];

    // Fixed headers
    expect($headerRow[0])->toBe('nama')
        ->and($headerRow[3])->toBe('kode_barang');

    // Dynamic tier headers
    $headerStr = implode('|', $headerRow);
    expect($headerStr)->toContain('harga_eceran_base')
        ->and($headerStr)->toContain('harga_grosir_base')
        ->and($headerStr)->toContain('harga_eceran_satuan_5'); // 5 slot

    // Instruksi sheet ada
    expect($loaded->getSheetByName('Instruksi'))->not->toBeNull();
});

it('preview: semua baris baru → summary insert=N, error=0, TIDAK menulis ke DB', function () {
    Auth::login(ownerForImport());
    $countBefore = Product::count();

    $xlsx = buildImportXlsx([
        ['nama' => 'Imp Test 1', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-001', 'satuan_base' => 'pcs', 'harga_eceran_base' => 15000],
        ['nama' => 'Imp Test 2', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-002', 'satuan_base' => 'pcs', 'harga_eceran_base' => 25000],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $response = callImportController('preview', $req);
    $body = json_decode($response->getContent(), true);

    expect($body['summary'])->toBe([
        'insert' => 2, 'update' => 0, 'skip' => 0, 'warnings' => 0,
    ])
        ->and($body['fatal_errors'])->toBe([])
        // CRITICAL: preview TIDAK menulis ke DB
        ->and(Product::count())->toBe($countBefore);
});

it('preview + commit: insert baru → DB rows + units + prices benar', function () {
    Auth::login(ownerForImport());
    $defaultId = PriceTier::where('is_default', true)->value('id');

    $xlsx = buildImportXlsx([
        ['nama' => 'Imp Insert', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-INS', 'satuan_base' => 'pcs',
         'satuan_2' => 'dus', 'rasio_2' => 12,
         'harga_eceran_base' => 10000, 'harga_eceran_satuan_2' => 115000],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    callImportController('commit', $req);

    $product = Product::where('sku', 'IMP-INS')->with('units.prices', 'units.unit')->firstOrFail();
    expect($product->name)->toBe('Imp Insert')
        ->and((float) $product->price)->toBe(10000.0) // legacy auto-sync dari base default
        ->and($product->units)->toHaveCount(2);

    $base = $product->units->firstWhere('level', 1);
    $dus = $product->units->firstWhere('level', 2);
    expect($base->unit->code)->toBe('pcs')
        ->and((float) $base->conversion_to_base)->toBe(1.0)
        ->and($dus->unit->code)->toBe('dus')
        ->and((float) $dus->conversion_to_base)->toBe(12.0)
        ->and((float) $base->prices->firstWhere('price_tier_id', $defaultId)->price)->toBe(10000.0)
        ->and((float) $dus->prices->firstWhere('price_tier_id', $defaultId)->price)->toBe(115000.0);
});

it('commit: update existing → master + harga berubah, kode_barang sebagai matcher', function () {
    Auth::login(ownerForImport());
    $defaultId = PriceTier::where('is_default', true)->value('id');

    // Seed existing produk via DB direct (bukan ProductController, supaya test isolated)
    $catId = Category::where('name', 'Pakan Kucing')->value('id');
    $pcsId = MasterUnit::where('code', 'pcs')->value('id');
    $product = Product::create([
        'sku' => 'IMP-UPD', 'name' => 'Nama Lama', 'category_id' => $catId,
        'base_unit_id' => $pcsId, 'type' => 'saleable_retail', 'price' => 5000,
    ]);
    $baseUnit = ProductUnit::create([
        'product_id' => $product->id, 'unit_id' => $pcsId, 'level' => 1,
        'conversion_to_base' => 1, 'is_purchase_unit' => true, 'is_sale_unit' => true,
    ]);
    ProductUnitPrice::create(['product_unit_id' => $baseUnit->id,
        'price_tier_id' => $defaultId, 'price' => 5000]);

    $xlsx = buildImportXlsx([
        ['nama' => 'Nama BARU', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-UPD', 'satuan_base' => 'pcs', 'harga_eceran_base' => 8888],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $response = callImportController('commit', $req);
    $body = json_decode($response->getContent(), true);

    expect($body['summary']['update'])->toBe(1)
        ->and($body['summary']['insert'])->toBe(0);

    $fresh = $product->fresh()->load('units.prices');
    expect($fresh->name)->toBe('Nama BARU')
        ->and((float) $fresh->price)->toBe(8888.0)
        ->and((float) $fresh->units->first()->prices->firstWhere('price_tier_id', $defaultId)->price)
            ->toBe(8888.0);
});

it('GUARDRAIL: rasio satuan existing BERBEDA → SKIP perubahan rasio + WARNING (harga tetap update)', function () {
    Auth::login(ownerForImport());
    $defaultId = PriceTier::where('is_default', true)->value('id');
    $catId = Category::where('name', 'Pakan Kucing')->value('id');
    $pcsId = MasterUnit::where('code', 'pcs')->value('id');
    $dusId = MasterUnit::where('code', 'dus')->value('id');

    $product = Product::create([
        'sku' => 'IMP-GUARD', 'name' => 'Guard', 'category_id' => $catId,
        'base_unit_id' => $pcsId, 'type' => 'saleable_retail', 'price' => 10000,
    ]);
    $base = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $pcsId,
        'level' => 1, 'conversion_to_base' => 1, 'is_sale_unit' => true]);
    $dus = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $dusId,
        'level' => 2, 'conversion_to_base' => 12, 'is_sale_unit' => true]);
    ProductUnitPrice::create(['product_unit_id' => $base->id, 'price_tier_id' => $defaultId, 'price' => 10000]);
    ProductUnitPrice::create(['product_unit_id' => $dus->id, 'price_tier_id' => $defaultId, 'price' => 100000]);

    // Excel kirim rasio dus = 24 (beda dari DB 12), dan harga dus baru = 200000
    $xlsx = buildImportXlsx([
        ['nama' => 'Guard', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-GUARD', 'satuan_base' => 'pcs',
         'satuan_2' => 'dus', 'rasio_2' => 24,
         'harga_eceran_base' => 10000, 'harga_eceran_satuan_2' => 200000],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $response = callImportController('commit', $req);
    $body = json_decode($response->getContent(), true);

    // Warning ada di report
    expect($body['summary']['warnings'])->toBeGreaterThan(0);
    $warnings = collect($body['rows'])->firstWhere('sku', 'IMP-GUARD')['warnings'];
    expect($warnings)->not->toBeEmpty()
        ->and(implode(' ', $warnings))->toContain('rasio')
        ->and(implode(' ', $warnings))->toContain('dus');

    // Rasio dus TIDAK berubah (tetap 12)
    expect((float) $dus->fresh()->conversion_to_base)->toBe(12.0);

    // Tapi harga dus TETAP di-update ke 200000
    expect((float) ProductUnitPrice::where('product_unit_id', $dus->id)
        ->where('price_tier_id', $defaultId)->value('price'))->toBe(200000.0);
});

it('GUARDRAIL: stok TIDAK PERNAH disentuh saat update produk', function () {
    Auth::login(ownerForImport());
    $defaultId = PriceTier::where('is_default', true)->value('id');
    $catId = Category::where('name', 'Pakan Kucing')->value('id');
    $pcsId = MasterUnit::where('code', 'pcs')->value('id');

    $product = Product::create([
        'sku' => 'IMP-STOK', 'name' => 'Stok Test', 'category_id' => $catId,
        'base_unit_id' => $pcsId, 'type' => 'saleable_retail', 'price' => 5000,
    ]);
    $base = ProductUnit::create(['product_id' => $product->id, 'unit_id' => $pcsId,
        'level' => 1, 'conversion_to_base' => 1, 'is_sale_unit' => true]);
    ProductUnitPrice::create(['product_unit_id' => $base->id, 'price_tier_id' => $defaultId, 'price' => 5000]);

    // Set inventory existing
    $warehouseId = \App\Models\Tenant\Warehouse::first()->id;
    Inventory::withoutGlobalScopes()->updateOrInsert(
        ['product_id' => $product->id, 'warehouse_id' => $warehouseId],
        ['qty' => 77, 'cost_avg' => 4000, 'updated_at' => now(), 'created_at' => now()],
    );

    $xlsx = buildImportXlsx([
        ['nama' => 'Stok Test Updated', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-STOK', 'satuan_base' => 'pcs', 'harga_eceran_base' => 9999],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    callImportController('commit', $req);

    // Inventory IDENTIK dgn sebelum import — guardrail terbukti
    $inv = Inventory::withoutGlobalScopes()
        ->where('product_id', $product->id)->where('warehouse_id', $warehouseId)->first();
    expect((float) $inv->qty)->toBe(77.0)
        ->and((float) $inv->cost_avg)->toBe(4000.0);

    // Master ke-update
    expect($product->fresh()->name)->toBe('Stok Test Updated');
});

it('ERROR: kode_barang duplikat dalam 1 file → row kedua di-skip dgn error', function () {
    Auth::login(ownerForImport());

    $xlsx = buildImportXlsx([
        ['nama' => 'Dup1', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-DUP', 'satuan_base' => 'pcs', 'harga_eceran_base' => 1000],
        ['nama' => 'Dup2', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-DUP', 'satuan_base' => 'pcs', 'harga_eceran_base' => 2000],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $response = callImportController('preview', $req);
    $body = json_decode($response->getContent(), true);

    expect($body['summary']['insert'])->toBe(1)
        ->and($body['summary']['skip'])->toBe(1);

    $second = collect($body['rows'])->firstWhere('row_num', 3);
    expect($second['action'])->toBe('skip')
        ->and(implode(' ', $second['errors']))->toContain('duplikat');
});

it('ERROR: rasio < 0.01 → baris di-skip dgn error', function () {
    Auth::login(ownerForImport());

    $xlsx = buildImportXlsx([
        ['nama' => 'Bad Ratio', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-RATIO', 'satuan_base' => 'pcs',
         'satuan_2' => 'dus', 'rasio_2' => 0.0001, // typo trap
         'harga_eceran_base' => 1000],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $response = callImportController('preview', $req);
    $body = json_decode($response->getContent(), true);

    expect($body['summary']['skip'])->toBe(1);
    $row = $body['rows'][0];
    expect(implode(' ', $row['errors']))->toContain('rasio')
        ->and(implode(' ', $row['errors']))->toContain('terlalu kecil');
});

it('ERROR: harga tier default base kosong → baris di-skip', function () {
    Auth::login(ownerForImport());

    $xlsx = buildImportXlsx([
        ['nama' => 'No Price', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-NOPRICE', 'satuan_base' => 'pcs'],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $body = json_decode(callImportController('preview', $req)->getContent(), true);
    expect($body['summary']['skip'])->toBe(1)
        ->and(implode(' ', $body['rows'][0]['errors']))->toContain('tier default');
});

it('ERROR: kategori belum exist → baris di-skip dgn error (sesuai keputusan owner)', function () {
    Auth::login(ownerForImport());

    $xlsx = buildImportXlsx([
        ['nama' => 'New Cat', 'kategori' => 'Kategori Yang Tidak Ada',
         'jenis' => 'saleable_retail', 'kode_barang' => 'IMP-NEWCAT',
         'satuan_base' => 'pcs', 'harga_eceran_base' => 1000],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $body = json_decode(callImportController('preview', $req)->getContent(), true);
    expect($body['summary']['skip'])->toBe(1)
        ->and(implode(' ', $body['rows'][0]['errors']))->toContain('kategori');
});

it('PARTIAL: 2 valid + 1 invalid → 2 sukses di-apply, 1 di-skip, partial commit', function () {
    Auth::login(ownerForImport());

    $xlsx = buildImportXlsx([
        ['nama' => 'OK1', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-OK1', 'satuan_base' => 'pcs', 'harga_eceran_base' => 1000],
        ['nama' => 'BAD', 'kategori' => 'Pakan Kucing', 'jenis' => 'INVALID_JENIS',
         'kode_barang' => 'IMP-BAD', 'satuan_base' => 'pcs', 'harga_eceran_base' => 2000],
        ['nama' => 'OK2', 'kategori' => 'Pakan Kucing', 'jenis' => 'saleable_retail',
         'kode_barang' => 'IMP-OK2', 'satuan_base' => 'pcs', 'harga_eceran_base' => 3000],
    ]);
    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($xlsx, 'imp.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $body = json_decode(callImportController('commit', $req)->getContent(), true);

    expect($body['summary']['insert'])->toBe(2)
        ->and($body['summary']['skip'])->toBe(1)
        ->and(Product::where('sku', 'IMP-OK1')->exists())->toBeTrue()
        ->and(Product::where('sku', 'IMP-OK2')->exists())->toBeTrue()
        ->and(Product::where('sku', 'IMP-BAD')->exists())->toBeFalse();
});

it('FATAL: header wajib hilang → fatal_errors + tidak ada row diproses', function () {
    Auth::login(ownerForImport());

    // File dgn header incomplete (missing harga_eceran_base)
    $ss = new Spreadsheet;
    $sh = $ss->getActiveSheet();
    $sh->setCellValue('A1', 'nama');
    $sh->setCellValue('B1', 'kode_barang');
    $sh->setCellValue('A2', 'Test');
    $sh->setCellValue('B2', 'IMP-FATAL');
    $tmp = tempnam(sys_get_temp_dir(), 'import-fatal-').'.xlsx';
    (new Xlsx($ss))->save($tmp);

    $req = Request::create('', 'POST');
    $req->setUserResolver(fn () => Auth::user());
    $req->files->set('file', new UploadedFile($tmp, 'fatal.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true));

    $body = json_decode(callImportController('preview', $req)->getContent(), true);
    expect($body['fatal_errors'])->not->toBeEmpty()
        ->and($body['rows'])->toBe([])
        ->and($body['summary']['insert'])->toBe(0);
});

it('OTORISASI: user tanpa master.manage ditolak', function () {
    $cashier = TenantUser::firstOrCreate(['email' => 'cashier-imp@vetly.id'], [
        'name' => 'Cashier', 'password' => bcrypt('x'), 'is_active' => true,
    ]);
    $cashier->syncRoles(['cashier']);
    Auth::login($cashier);

    expect(fn () => callImportController('downloadTemplate'))->toThrow(AuthorizationException::class);
});
