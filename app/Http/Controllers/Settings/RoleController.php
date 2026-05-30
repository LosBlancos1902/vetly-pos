<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Role / permission management. Owners view all preset roles and edit which
 * permissions are bound to each. Creating CUSTOM roles is deferred to a
 * later phase — this UI only `syncPermissions` on existing rows.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $this->authorize('settings.roles');

        $roles = Role::with('permissions:id,name')->orderBy('name')->get(['id', 'name']);
        $permissions = Permission::orderBy('name')->get(['id', 'name']);

        // Group permissions by prefix (pos.*, inventory.*, master.*, …) so the UI
        // can render them in collapsible sections.
        $groups = $permissions->groupBy(fn ($p) => explode('.', $p->name, 2)[0])
            ->map(fn ($items) => $items->values())
            ->sortKeys();

        return Inertia::render('Settings/Roles', [
            'roles' => $roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'permission_names' => $r->permissions->pluck('name')->values(),
            ]),
            'permissionGroups' => $groups,
        ]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $this->authorize('settings.roles');

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $oldPermissions = $role->getPermissionNames()->all();
        $newPermissions = $data['permissions'] ?? [];

        $role->syncPermissions($newPermissions);
        Artisan::call('permission:cache-reset');

        // Sync permission = pivot write → log manual untuk Riwayat Aktivitas.
        activity('roles')
            ->performedOn($role)
            ->causedBy($request->user())
            ->event('permissions_synced')
            ->withProperties(['old' => $oldPermissions, 'new' => $newPermissions])
            ->log("Permission role '{$role->name}' diperbarui");

        return back()->with('success', "Permission role '{$role->name}' diperbarui.");
    }
}
