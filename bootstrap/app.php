<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        /**
         * Convert non-Inertia-aware exceptions into back-redirect + flash error
         * for Inertia requests. Tanpa ini, abort(422, "msg") render HTML error
         * page mentah ke user (penyebab bug UX).
         *
         * - ValidationException (field errors): biarkan native (Inertia handle
         *   via errors prop → onError callback per form).
         * - HttpException 4xx (abort dgn pesan): redirect back + flash error msg
         *   yang ramah → frontend tampilkan sbg toast.
         * - 500 di local/testing: biarkan full error utk debugging dev.
         * - 500 di production: flash pesan generik (stack trace stay di log).
         */
        $exceptions->respond(function (Response $response, \Throwable $exception, Request $request) {
            if (! $request->header('X-Inertia')) {
                return $response;
            }

            // ValidationException sudah Inertia-aware (field errors via JSON 422).
            if ($exception instanceof ValidationException) {
                return $response;
            }

            $status = $response->getStatusCode();

            if ($status === 419) {
                return back()->with('error', 'Sesi kedaluwarsa. Silakan coba lagi.');
            }

            if (in_array($status, [400, 401, 403, 404, 405, 422, 429], true)) {
                $msg = trim((string) $exception->getMessage());
                if ($msg === '') {
                    $msg = match ($status) {
                        401 => 'Anda harus login dulu.',
                        403 => 'Anda tidak punya akses untuk aksi ini.',
                        404 => 'Data tidak ditemukan.',
                        405 => 'Metode tidak diizinkan.',
                        429 => 'Terlalu banyak permintaan, coba beberapa saat lagi.',
                        default => 'Aksi ditolak.',
                    };
                }

                return back()->with('error', $msg);
            }

            if ($status >= 500) {
                // Local/testing: developer butuh full error utk debug.
                if (app()->environment(['local', 'testing'])) {
                    return $response;
                }

                // Production: stack trace stay di log (Laravel default behavior),
                // user lihat pesan sopan.
                return back()->with('error', 'Terjadi kesalahan server. Tim teknis sudah diberi tahu.');
            }

            return $response;
        });
    })->create();
