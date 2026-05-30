<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Riwayat Aktivitas — viewer READ-ONLY untuk spatie activity_log
 * (perubahan master data & settings: siapa, kapan, dari nilai apa ke apa).
 *
 * Sengaja HANYA membaca activity_log. Tabel audit_logs lama (POS forensic
 * trail) TIDAK ditampilkan di sini — dua sistem dipisah sesuai keputusan owner.
 *
 * Gated `audit.view` (owner + manager).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('audit.view');

        $activities = Activity::query()
            ->with('causer')
            ->when($request->causer_id, fn ($q, $v) => $q->where('causer_id', $v))
            ->when($request->event, fn ($q, $v) => $q->where('event', $v))
            ->when($request->subject_type, fn ($q, $v) => $q->where('subject_type', $v))
            ->when($request->log_name, fn ($q, $v) => $q->where('log_name', $v))
            ->when($request->date_from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (Activity $a) => [
                'id' => $a->id,
                'log_name' => $a->log_name,
                'event' => $a->event,
                'description' => $a->description,
                'subject_type' => $a->subject_type ? class_basename($a->subject_type) : null,
                'subject_id' => $a->subject_id,
                'causer' => $a->causer ? ['id' => $a->causer->id, 'name' => $a->causer->name] : null,
                'properties' => $a->properties,
                'created_at' => optional($a->created_at)->toIso8601String(),
            ]);

        return Inertia::render('Settings/ActivityLog', [
            'activities' => $activities,
            'filters' => $request->only([
                'causer_id', 'event', 'subject_type', 'log_name', 'date_from', 'date_to',
            ]),
            'users' => User::orderBy('name')->get(['id', 'name']),
            'subjectTypes' => Activity::query()
                ->whereNotNull('subject_type')
                ->distinct()
                ->pluck('subject_type')
                ->map(fn ($t) => ['value' => $t, 'label' => class_basename($t)])
                ->values(),
            'events' => Activity::query()
                ->whereNotNull('event')
                ->distinct()
                ->orderBy('event')
                ->pluck('event')
                ->values(),
            'logNames' => Activity::query()
                ->whereNotNull('log_name')
                ->distinct()
                ->orderBy('log_name')
                ->pluck('log_name')
                ->values(),
        ]);
    }
}
