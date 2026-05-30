<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coa extends Model
{
    protected $table = 'coa';
    use \Spatie\Activitylog\Traits\LogsActivity;
    use \App\Models\Tenant\Concerns\LogsTenantActivity;

    public const ACTIVITY_LOG_NAME = 'master';

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    /**
     * Akun inti yang di-reference JournalEngine secara verbatim (by-code) atau
     * struktural (parent heading). Kode + type/normal_balance TERKUNCI, akun
     * TIDAK boleh dihapus — kalau berubah/hilang, posting jurnal pecah.
     * (Lapis ke-2 lock = punya journal_entries; lihat isUsedInJournal.)
     */
    public const SYSTEM_ACCOUNTS = [
        '1100', '1101', '1102', '1103', '1104', '1105', '1106',
        '1200', '1201', '1203',
        '2100', '2101', '2102',
        '3100', '3101',
        '4100', '4101', '4102', '4103', '4104', '4199',
        '5100', '5101', '5102',
        '6100',
    ];

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'coa_id');
    }

    /** Akun inti sistem (by-code) — kode/type terkunci permanen. */
    public function isSystem(): bool
    {
        return in_array($this->code, self::SYSTEM_ACCOUNTS, true);
    }

    /** Sudah pernah dipakai di jurnal? (lock lapis kedua) */
    public function isUsedInJournal(): bool
    {
        return JournalEntry::where('coa_id', $this->id)->exists();
    }

    /** Terkunci dari hapus / ubah kode-type. */
    public function isLocked(): bool
    {
        return $this->isSystem() || $this->isUsedInJournal();
    }

    /** Akun kas/bank (untuk dropdown sumber transaksi Kas & Bank). */
    public function isCashOrBank(): bool
    {
        return in_array($this->cash_type, ['cash', 'bank'], true);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
