<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use Inertia\Inertia;
use Inertia\Response;

class TenantSettingsController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Settings/Tenant', [
            'tenant' => tenant() ? ['id' => tenant('id')] : null,
            'users' => User::with('roles:id,name')->get(['id', 'name', 'email', 'is_active']),
        ]);
    }
}
