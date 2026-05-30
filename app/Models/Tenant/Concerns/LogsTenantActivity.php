<?php

namespace App\Models\Tenant\Concerns;

use Spatie\Activitylog\LogOptions;

/**
 * Konfigurasi activity-log bersama untuk model master/settings tenant.
 *
 * Dipakai BARENG trait spatie:
 *   use \Spatie\Activitylog\Traits\LogsActivity;
 *   use \App\Models\Tenant\Concerns\LogsTenantActivity;
 *
 * Perilaku:
 *   - logAll() + logExcept(): log semua kolom kecuali noise/sensitif.
 *   - logOnlyDirty(): pada update hanya simpan field yang berubah (old→new).
 *   - dontSubmitEmptyLogs(): skip kalau tidak ada perubahan ter-log.
 *   - log_name dari const ACTIVITY_LOG_NAME (utk filter UI), default 'default'.
 *   - const ACTIVITY_EXCEPT (opsional) untuk exclude field per-model
 *     (mis. BrandingSettings->logo_data blob base64).
 *
 * Isolasi tenant: tabel activity_log hidup di TENANT DB. config
 * activitylog.database_connection = null → spatie pakai default connection,
 * yang di-swap stancl/tenancy ke tenant DB saat request/console tenant.
 * Jadi log otomatis ter-isolasi per tenant tanpa config tambahan.
 */
trait LogsTenantActivity
{
    public function getActivitylogOptions(): LogOptions
    {
        $except = array_merge(
            [
                'id', 'created_at', 'updated_at', 'deleted_at',
                'password', 'remember_token', 'api_token', 'token', 'remember',
            ],
            defined('static::ACTIVITY_EXCEPT') ? static::ACTIVITY_EXCEPT : []
        );

        return LogOptions::defaults()
            ->logAll()
            ->logExcept($except)
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName(defined('static::ACTIVITY_LOG_NAME') ? static::ACTIVITY_LOG_NAME : 'default');
    }
}
