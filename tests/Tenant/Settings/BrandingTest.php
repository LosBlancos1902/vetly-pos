<?php

use App\Http\Controllers\Settings\BrandingController;
use App\Models\Tenant\BrandingSettings;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;

/**
 * Branding Struk — CRUD + fallback.
 *
 *   - Permission: owner only (gate `settings.tenant`)
 *   - Tenant-level CRUD (brand_name, footer, npwp, license_no, logo)
 *   - Logo upload: mime + size validation
 *   - Per-warehouse CRUD (address, phone, footer_override)
 *   - Fallback: branding kosong → singleton() tetap return row, semua nullable
 *
 * Cleanup: reset branding singleton + un-set warehouse branding fields
 * pada test warehouse pakai prefix WH-BRAND-.
 */

function ownerForBranding(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function managerWithoutTenantPerm(): TenantUser
{
    $u = TenantUser::firstOrCreate(
        ['email' => 'manager-brand@test.local'],
        ['name' => 'Manager Brand', 'password' => bcrypt('x'), 'is_active' => true],
    );
    if (! $u->hasRole('manager')) {
        $u->syncRoles(['manager']);
    }

    return $u->fresh();
}

function callBranding(string $method, array $payload = [], ?Warehouse $warehouse = null, ?UploadedFile $logo = null)
{
    $controller = app(BrandingController::class);

    return match ($method) {
        'index' => (function () use ($controller) {
            return $controller->index();
        })(),
        'updateTenant' => (function () use ($controller, $payload, $logo) {
            $req = Request::create('/settings/branding/tenant', 'POST', $payload);
            $req->setUserResolver(fn () => Auth::user());
            if ($logo) {
                $req->files->set('logo', $logo);
            }

            return $controller->updateTenant($req);
        })(),
        'updateWarehouse' => (function () use ($controller, $payload, $warehouse) {
            $req = Request::create('/settings/branding/warehouses/'.$warehouse->id, 'PUT', $payload);
            $req->setUserResolver(fn () => Auth::user());

            return $controller->updateWarehouse($req, $warehouse);
        })(),
    };
}

/** Generate a tiny valid PNG file on tmp path. */
function makePngTmp(int $bytes = 256): string
{
    // 1×1 transparent PNG (67 bytes) repeated padding kalau perlu.
    $tinyPng = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
    );
    $tmp = tempnam(sys_get_temp_dir(), 'brand_logo_').'.png';
    file_put_contents($tmp, $tinyPng);

    if ($bytes > strlen($tinyPng)) {
        // Append junk to bloat file size; PNG akan tetap detect mimetype dari magic bytes.
        file_put_contents($tmp, str_repeat('A', $bytes - strlen($tinyPng)), FILE_APPEND);
    }

    return $tmp;
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForBranding());

    // Reset singleton
    BrandingSettings::query()->delete();

    // Cleanup warehouse branding fields utk warehouse test (prefix WH-BRAND-).
    Warehouse::where('code', 'like', 'WH-BRAND-%')->delete();
});

afterEach(function () {
    BrandingSettings::query()->delete();
    Warehouse::where('code', 'like', 'WH-BRAND-%')->delete();
    Auth::logout();
});

// ─── INDEX / PERMISSION ─────────────────────────────────────────────

it('INDEX: owner bisa akses Branding page', function () {
    $resp = callBranding('index');
    $props = $resp->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    expect($props)->toHaveKeys(['branding', 'warehouses', 'tenantName', 'logoMaxKb']);
    expect($props['branding']['brand_name'])->toBeNull();
});

it('PERM: manager (tanpa settings.tenant) → AuthorizationException', function () {
    Auth::login(managerWithoutTenantPerm());

    expect(fn () => callBranding('index'))->toThrow(AuthorizationException::class);
});

it('PERM: manager tdk boleh updateTenant', function () {
    Auth::login(managerWithoutTenantPerm());

    expect(fn () => callBranding('updateTenant', ['brand_name' => 'X']))
        ->toThrow(AuthorizationException::class);
});

// ─── FALLBACK SINGLETON ──────────────────────────────────────────────

it('FALLBACK: singleton() return row meskipun belum pernah disimpan', function () {
    expect(BrandingSettings::count())->toBe(0);

    $b = BrandingSettings::singleton();
    expect($b->id)->toBe(1);
    expect($b->brand_name)->toBeNull();
    expect($b->logo_data)->toBeNull();
    expect($b->footer_text)->toBeNull();

    // Idempotent — tetap row yang sama
    $b2 = BrandingSettings::singleton();
    expect($b2->id)->toBe(1);
    expect(BrandingSettings::count())->toBe(1);
});

// ─── UPDATE TENANT — TEXT FIELDS ────────────────────────────────────

it('UPDATE_TENANT: set brand_name + footer + npwp + license', function () {
    callBranding('updateTenant', [
        'brand_name' => 'Petshop Bahagia',
        'footer_text' => "Terima kasih\nbarang tidak bisa dikembalikan",
        'npwp' => '01.234.567.8-901.000',
        'license_no' => 'SIUP/2024/0001',
    ]);

    $b = BrandingSettings::singleton();
    expect($b->brand_name)->toBe('Petshop Bahagia');
    expect($b->footer_text)->toContain('barang tidak bisa dikembalikan');
    expect($b->npwp)->toBe('01.234.567.8-901.000');
    expect($b->license_no)->toBe('SIUP/2024/0001');
});

it('UPDATE_TENANT: field kosong → tersimpan null (clear)', function () {
    callBranding('updateTenant', ['brand_name' => 'X']);
    expect(BrandingSettings::singleton()->brand_name)->toBe('X');

    callBranding('updateTenant', ['brand_name' => '']);
    expect(BrandingSettings::singleton()->brand_name)->toBeNull();
});

// ─── UPDATE TENANT — LOGO ──────────────────────────────────────────

it('LOGO: upload PNG → tersimpan base64 data URI', function () {
    $tmp = makePngTmp(256);
    $file = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);

    callBranding('updateTenant', ['brand_name' => 'Test'], null, $file);

    $b = BrandingSettings::singleton();
    expect($b->logo_data)->toStartWith('data:image/png;base64,');
    expect($b->logo_mime)->toBe('image/png');
});

it('LOGO: remove_logo=1 → clear logo data', function () {
    // Set logo first
    $tmp = makePngTmp(256);
    $file = new UploadedFile($tmp, 'logo.png', 'image/png', null, true);
    callBranding('updateTenant', [], null, $file);
    expect(BrandingSettings::singleton()->logo_data)->not->toBeNull();

    // Remove
    callBranding('updateTenant', ['remove_logo' => '1']);
    $b = BrandingSettings::singleton();
    expect($b->logo_data)->toBeNull();
    expect($b->logo_mime)->toBeNull();
});

it('LOGO: file > 200KB → 422 ValidationException', function () {
    $tmp = makePngTmp(300 * 1024); // 300KB
    $file = new UploadedFile($tmp, 'big.png', 'image/png', null, true);

    expect(fn () => callBranding('updateTenant', [], null, $file))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

// ─── UPDATE WAREHOUSE ──────────────────────────────────────────────

it('UPDATE_WH: set address + phone + footer_override per cabang', function () {
    $w = Warehouse::create([
        'code' => 'WH-BRAND-001',
        'name' => 'Cabang Brand 1',
        'warehouse_type' => 'petshop',
        'is_active' => true,
        'is_default' => false,
    ]);

    callBranding('updateWarehouse', [
        'address' => "Jl. Mawar No. 1\nJakarta",
        'phone' => '021-555-0001',
        'footer_override' => 'Cabang ini buka 24 jam',
    ], $w);

    $w->refresh();
    expect($w->address)->toContain('Jl. Mawar');
    expect($w->phone)->toBe('021-555-0001');
    expect($w->footer_override)->toBe('Cabang ini buka 24 jam');
});

it('PERM: manager tdk boleh updateWarehouse branding', function () {
    $w = Warehouse::create([
        'code' => 'WH-BRAND-002',
        'name' => 'Cabang Brand 2',
        'warehouse_type' => 'petshop',
        'is_active' => true,
        'is_default' => false,
    ]);

    Auth::login(managerWithoutTenantPerm());

    expect(fn () => callBranding('updateWarehouse', ['phone' => '021-1'], $w))
        ->toThrow(AuthorizationException::class);
});

// ─── INDEX REFLECT WAREHOUSE BRANDING ──────────────────────────────

it('INDEX: warehouses props include branding fields (address/phone/footer_override)', function () {
    $w = Warehouse::create([
        'code' => 'WH-BRAND-003',
        'name' => 'Cabang Brand 3',
        'warehouse_type' => 'petshop',
        'is_active' => true,
        'is_default' => false,
        'address' => 'Jl. Tes',
        'phone' => '021-9',
        'footer_override' => 'Footer cabang',
    ]);

    $props = callBranding('index')
        ->toResponse(request())->getOriginalContent()->getData()['page']['props'];

    $found = collect($props['warehouses'])->firstWhere('code', 'WH-BRAND-003');
    expect($found)->not->toBeNull();
    expect($found['address'])->toBe('Jl. Tes');
    expect($found['phone'])->toBe('021-9');
    expect($found['footer_override'])->toBe('Footer cabang');
});
