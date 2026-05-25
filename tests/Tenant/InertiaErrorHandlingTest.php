<?php

use Illuminate\Support\Facades\Route;

/**
 * Verify withExceptions handler convert HttpException (abort) di Inertia request
 * jadi redirect + flash error — bukan render HTML error page mentah.
 *
 * Pre-fix: response status 422 dgn HTML body (browser tampilkan halaman error).
 * Post-fix: response 302/303 redirect + session('error') terisi pesan ramah.
 */
it('Inertia request: abort(422, "msg") → redirect back + flash error', function () {
    // Daftar rute uji on-the-fly. Tidak ganggu rute existing — closure-only.
    Route::get('/__test_abort_422', function () {
        abort(422, 'Pesan validasi yang ramah ke user');
    })->middleware('web');

    $resp = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
    ])->get('/__test_abort_422');

    // Inertia rewrite 302 redirect → 409 (external visit) atau 303 (PUT/POST→GET).
    expect($resp->getStatusCode())->toBeIn([302, 303, 409])
        ->and(session('error'))->toBe('Pesan validasi yang ramah ke user');
});

it('Inertia request: abort(403) tanpa msg → flash default sopan', function () {
    Route::get('/__test_abort_403', function () {
        abort(403);
    })->middleware('web');

    $resp = $this->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '1',
    ])->get('/__test_abort_403');

    // Inertia rewrite 302 redirect → 409 (external visit) atau 303 (PUT/POST→GET).
    expect($resp->getStatusCode())->toBeIn([302, 303, 409])
        ->and(session('error'))->toBe('Anda tidak punya akses untuk aksi ini.');
});

it('NON-Inertia request: abort(422) → response 422 normal (tidak di-rewrite)', function () {
    Route::get('/__test_abort_non_inertia', function () {
        abort(422, 'Pesan untuk API client');
    })->middleware('web');

    // Tanpa X-Inertia header → handler harus pass-through.
    $resp = $this->get('/__test_abort_non_inertia');

    expect($resp->getStatusCode())->toBe(422);
});
