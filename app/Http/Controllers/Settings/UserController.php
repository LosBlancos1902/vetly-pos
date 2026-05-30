<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use App\Models\Tenant\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

/**
 * Tenant user management. Owners assign roles + warehouse here.
 *
 * RBAC contract: roles are preset templates; this UI does NOT let you create
 * custom roles (use /settings/roles for that). It only binds a user to ONE
 * role + at most ONE fixed warehouse.
 */
class UserController extends Controller
{
    /** Roles that imply cross-warehouse access (warehouse_id may be NULL). */
    private const CROSS_WAREHOUSE_ROLES = ['owner', 'manager'];

    public function index(): Response
    {
        $this->authorize('settings.users');

        $users = User::with(['roles:id,name', 'warehouse:id,code,name'])
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'phone', 'warehouse_id', 'is_active', 'last_login_at']);

        return Inertia::render('Settings/Users', [
            'users' => $users,
            'roles' => Role::orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::active()->orderBy('name')->get(['id', 'code', 'name']),
            'crossWarehouseRoles' => self::CROSS_WAREHOUSE_ROLES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('settings.users');

        $data = $this->validateUser($request, isUpdate: false);

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => Hash::make($data['password']),
            'is_active' => $data['is_active'] ?? true,
            'warehouse_id' => $this->resolveWarehouseId($data),
        ]);

        $user->syncRoles([$data['role']]);

        // Role assignment = pivot write → trait LogsActivity tidak menangkapnya.
        // Log manual supaya tercatat di Riwayat Aktivitas.
        activity('users')
            ->performedOn($user)
            ->causedBy($request->user())
            ->event('role_assigned')
            ->withProperties(['old' => [], 'new' => [$data['role']]])
            ->log("Role user {$user->name} di-set: {$data['role']}");

        return back()->with('success', "User {$user->name} ditambahkan.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $this->authorize('settings.users');

        $data = $this->validateUser($request, isUpdate: true, userId: $user->id);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'warehouse_id' => $this->resolveWarehouseId($data),
        ]);

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        $oldRoles = $user->getRoleNames()->all();
        $user->syncRoles([$data['role']]);

        // Log perubahan role (pivot, tidak ter-capture trait) hanya bila berubah.
        if ($oldRoles !== [$data['role']]) {
            activity('users')
                ->performedOn($user)
                ->causedBy($request->user())
                ->event('role_assigned')
                ->withProperties(['old' => $oldRoles, 'new' => [$data['role']]])
                ->log("Role user {$user->name} diubah");
        }

        return back()->with('success', "User {$user->name} diperbarui.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('settings.users');

        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'Tidak bisa menghapus akun sendiri.']);
        }

        $name = $user->name;
        $user->delete();

        return back()->with('success', "User {$name} dihapus.");
    }

    private function validateUser(Request $request, bool $isUpdate, ?int $userId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', $isUpdate
                ? 'unique:users,email,'.$userId
                : 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => [$isUpdate ? 'nullable' : 'required', 'string', 'min:6'],
            'is_active' => ['boolean'],
            'role' => ['required', 'string', 'exists:roles,name'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ]);
    }

    /**
     * Cross-warehouse roles may have NULL; everyone else MUST be tied to one outlet.
     * Throws ValidationException-compatible 422 by returning back-with-errors.
     */
    private function resolveWarehouseId(array $data): ?int
    {
        $isCross = in_array($data['role'], self::CROSS_WAREHOUSE_ROLES, true);
        $wid = $data['warehouse_id'] ?? null;

        if (! $isCross && $wid === null) {
            abort(422, "Role '{$data['role']}' wajib terikat ke satu warehouse.");
        }

        // Cross-warehouse roles may keep NULL OR pin to one if specified.
        return $wid;
    }
}
