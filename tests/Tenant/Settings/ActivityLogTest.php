<?php

use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\RoleController;
use App\Http\Controllers\Settings\UserController;
use App\Models\Tenant\Sale;
use App\Models\Tenant\User as TenantUser;
use App\Models\Tenant\Warehouse;
use Database\Seeders\DefaultRolesSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Audit Log (spatie/laravel-activitylog) — master/settings only.
 *
 * Memastikan: model master ter-log (old→new), POS (Sale) TIDAK ter-log via
 * spatie, gate audit.view jalan, log manual role/permission tercatat, dan
 * field sensitif (password) tidak bocor ke properties.
 */
function ownerForAudit(): TenantUser
{
    return TenantUser::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->firstOrFail();
}

function cashierForAudit(): TenantUser
{
    $u = TenantUser::firstOrCreate(
        ['email' => 'cashier-audit@test.local'],
        ['name' => 'Cashier Audit', 'password' => bcrypt('x'), 'is_active' => true],
    );
    if (! $u->hasRole('cashier')) {
        $u->syncRoles(['cashier']);
    }

    return $u->fresh();
}

function callAudit(array $query = [])
{
    $controller = app(AuditLogController::class);
    $req = Request::create('/settings/audit-log', 'GET', $query);
    $req->setUserResolver(fn () => Auth::user());

    return $controller->index($req);
}

beforeEach(function () {
    (new DefaultRolesSeeder)->run();
    Auth::login(ownerForAudit());

    Activity::query()->delete();
    Warehouse::where('code', 'like', 'WH-AUDIT-%')->delete();
    Sale::where('invoice_no', 'INV-AUDIT-TEST')->delete();
});

afterEach(function () {
    Activity::query()->delete();
    Warehouse::where('code', 'like', 'WH-AUDIT-%')->delete();
    Sale::where('invoice_no', 'INV-AUDIT-TEST')->delete();
    Auth::logout();
});

// ─── COVERAGE STRUKTURAL: model mana yg ter-log ────────────────────

it('STRUKTUR: model master pakai trait LogsActivity, model POS/auto TIDAK', function () {
    $logged = [
        \App\Models\Tenant\Product::class,
        \App\Models\Tenant\Category::class,
        \App\Models\Tenant\Customer::class,
        \App\Models\Tenant\Supplier::class,
        \App\Models\Tenant\Warehouse::class,
        \App\Models\Tenant\Promo::class,
        \App\Models\Tenant\PromoApplication::class,
        \App\Models\Tenant\StockTransfer::class,
        \App\Models\Tenant\StockOpname::class,
        \App\Models\Tenant\PurchaseRequest::class,
        \App\Models\Tenant\PurchaseOrder::class,
        \App\Models\Tenant\GoodsReceipt::class,
        \App\Models\Tenant\Coa::class,
        \App\Models\Tenant\BrandingSettings::class,
        \App\Models\Tenant\User::class,
        // Akuntansi — cakupan diperluas (modul Kas & Bank): jurnal manual + backdate ter-audit.
        \App\Models\Tenant\Journal::class,
        \App\Models\Tenant\JournalEntry::class,
    ];
    foreach ($logged as $model) {
        expect(in_array(LogsActivity::class, class_uses_recursive($model), true))
            ->toBeTrue("$model harus pakai LogsActivity");
    }

    $notLogged = [
        \App\Models\Tenant\Sale::class,
        \App\Models\Tenant\SaleItem::class,
        \App\Models\Tenant\SalePayment::class,
        \App\Models\Tenant\StockMovement::class,
        \App\Models\Tenant\Inventory::class,
    ];
    foreach ($notLogged as $model) {
        expect(in_array(LogsActivity::class, class_uses_recursive($model), true))
            ->toBeFalse("$model TIDAK boleh pakai LogsActivity");
    }
});

// ─── FUNGSIONAL: create + update ter-log dgn old→new ───────────────

it('LOG: create Warehouse → activity created (log_name master)', function () {
    $before = Activity::count();

    Warehouse::create([
        'code' => 'WH-AUDIT-001',
        'name' => 'Cabang Audit 1',
        'warehouse_type' => 'petshop',
        'is_active' => true,
        'is_default' => false,
    ]);

    expect(Activity::count())->toBe($before + 1);
    $act = Activity::latest('id')->first();
    expect($act->event)->toBe('created');
    expect($act->log_name)->toBe('master');
    expect($act->causer_id)->toBe(ownerForAudit()->id);
    expect($act->properties['attributes']['name'])->toBe('Cabang Audit 1');
});

it('LOG: update Warehouse name → activity updated dgn old & new', function () {
    $w = Warehouse::create([
        'code' => 'WH-AUDIT-002',
        'name' => 'Nama Lama',
        'warehouse_type' => 'petshop',
        'is_active' => true,
        'is_default' => false,
    ]);

    $w->update(['name' => 'Nama Baru']);

    $act = Activity::where('event', 'updated')
        ->where('subject_type', Warehouse::class)
        ->where('subject_id', $w->id)
        ->latest('id')->first();

    expect($act)->not->toBeNull();
    expect($act->properties['attributes']['name'])->toBe('Nama Baru');
    expect($act->properties['old']['name'])->toBe('Nama Lama');
});

// ─── POS TIDAK TER-LOG via spatie ──────────────────────────────────

it('POS: membuat Sale TIDAK menambah activity spatie', function () {
    $before = Activity::count();

    Sale::create([
        'invoice_no' => 'INV-AUDIT-TEST',
        'date' => now(),
        'warehouse_id' => 1,
        'cashier_id' => ownerForAudit()->id,
    ]);

    expect(Activity::count())->toBe($before);
});

// ─── GATE audit.view ───────────────────────────────────────────────

it('GATE: owner bisa akses Riwayat Aktivitas', function () {
    $resp = callAudit();
    $props = $resp->toResponse(request())->getOriginalContent()->getData()['page']['props'];
    expect($props)->toHaveKeys(['activities', 'filters', 'users', 'subjectTypes', 'events', 'logNames']);
});

it('GATE: cashier (tanpa audit.view) → AuthorizationException', function () {
    Auth::login(cashierForAudit());
    expect(fn () => callAudit())->toThrow(AuthorizationException::class);
});

// ─── LOG MANUAL: role & permission ─────────────────────────────────

it('MANUAL: ganti role user → activity role_assigned old→new', function () {
    $u = TenantUser::firstOrCreate(
        ['email' => 'roletest-audit@test.local'],
        ['name' => 'Role Test', 'password' => bcrypt('x'), 'is_active' => true],
    );
    $u->syncRoles(['cashier']);
    Activity::query()->delete();

    $controller = app(UserController::class);
    $req = Request::create('/settings/users/'.$u->id, 'PUT', [
        'name' => 'Role Test',
        'email' => 'roletest-audit@test.local',
        'role' => 'supervisor',
        'is_active' => true,
        'warehouse_id' => 1,
    ]);
    $req->setUserResolver(fn () => Auth::user());
    $controller->update($req, $u);

    $act = Activity::where('event', 'role_assigned')->latest('id')->first();
    expect($act)->not->toBeNull();
    expect($act->properties['old'])->toContain('cashier');
    expect($act->properties['new'])->toContain('supervisor');
});

it('MANUAL: sync permission role → activity permissions_synced old→new', function () {
    $role = \Spatie\Permission\Models\Role::where('name', 'supervisor')->firstOrFail();
    Activity::query()->delete();

    $controller = app(RoleController::class);
    $req = Request::create('/settings/roles/'.$role->id, 'PUT', [
        'permissions' => ['pos.access', 'pos.sell'],
    ]);
    $req->setUserResolver(fn () => Auth::user());
    $controller->update($req, $role);

    $act = Activity::where('event', 'permissions_synced')->latest('id')->first();
    expect($act)->not->toBeNull();
    expect($act->properties['new'])->toContain('pos.access');
});

// ─── SENSITIF: password tidak bocor ────────────────────────────────

it('SENSITIF: create user via controller → properties tanpa password', function () {
    $controller = app(UserController::class);
    $req = Request::create('/settings/users', 'POST', [
        'name' => 'Secret User',
        'email' => 'secret-audit@test.local',
        'password' => 'rahasia123',
        'role' => 'cashier',
        'is_active' => true,
        'warehouse_id' => 1,
    ]);
    $req->setUserResolver(fn () => Auth::user());
    $controller->store($req);

    $act = Activity::where('event', 'created')
        ->where('subject_type', TenantUser::class)
        ->latest('id')->first();

    // User model getMorphClass() → App\Models\User, jadi cek by causer/desc fallback.
    $act = $act ?? Activity::where('event', 'created')->latest('id')->first();
    expect($act)->not->toBeNull();
    $props = json_encode($act->properties);
    expect($props)->not->toContain('rahasia123');
    expect($act->properties['attributes'] ?? [])->not->toHaveKey('password');
    expect($act->properties['attributes'] ?? [])->not->toHaveKey('remember_token');

    TenantUser::where('email', 'secret-audit@test.local')->delete();
});
