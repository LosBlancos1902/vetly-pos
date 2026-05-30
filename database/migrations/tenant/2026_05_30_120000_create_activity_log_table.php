<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabel activity_log (spatie/laravel-activitylog) untuk audit master/settings.
 *
 * Sengaja diletakkan di database/migrations/tenant/ (BUKAN central) supaya
 * tercipta di tiap tenant DB — mengikuti pola tabel permission
 * (2026_05_20_100100_create_permission_tables.php). Tidak menyentuh tabel
 * audit_logs lama (POS forensic trail) — dua sistem coexist.
 *
 * Skema = gabungan migrasi historis spatie (base + event + batch_uuid)
 * jadi satu file. Non-destruktif (create-only).
 */
return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('activitylog.table_name') ?: 'activity_log';

        Schema::create($tableName, function (Blueprint $table) {
            $table->bigIncrements('id');

            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();

            $table->index('log_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('activitylog.table_name') ?: 'activity_log');
    }
};
