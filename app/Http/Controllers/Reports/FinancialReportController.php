<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Journal;
use App\Models\Tenant\Warehouse;
use App\Services\Reports\ColumnPicker;
use App\Services\Reports\ReportExcelExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan keuangan READ-ONLY. Source of truth: journal_entries + coa.
 *
 * Pattern column picker:
 *   - private columnsXxx() return associative array (key => label+default+value).
 *   - Inertia render kirim ColumnPicker::publicMeta() ke FE (modal).
 *   - export=1 + columns[] di request → ColumnPicker::pick() filter & generate.
 *
 * Catatan v1: P&L = Laba KOTOR (Pendapatan − HPP). Beban operasional (6xxx)
 * di-display tapi saat ini KOSONG karena belum ada modul input beban manual
 * (Batch B). Disclaimer di UI eksplisit.
 */
class FinancialReportController extends Controller
{
    // ───────────────────────── Laba Rugi ─────────────────────────

    public function profitLoss(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request);

        $data = $this->plRows($f['from'], $f['to'], $f['warehouse_id']);
        $cols = $this->columnsPl();

        if ($request->boolean('export')) {
            return $this->plExport($data, $cols, $f, $this->selectedColumns($request));
        }

        return Inertia::render('Reports/Financial/ProfitLoss', [
            'filters' => $this->filtersOut($f),
            'warehouses' => $this->warehousesList(),
            'rows' => $data['accounts'],
            'totals' => $data['totals'],
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    private function columnsPl(): array
    {
        return [
            'code' => ['label' => 'Kode Akun', 'default' => true, 'value' => fn ($r) => $r->code],
            'name' => ['label' => 'Nama Akun', 'default' => true, 'value' => fn ($r) => $r->name],
            'type' => ['label' => 'Tipe', 'default' => true, 'value' => fn ($r) => $r->type],
            'amount' => ['label' => 'Nilai', 'default' => true, 'value' => fn ($r) => round((float) $r->amount, 2)],
            'total_debit' => ['label' => 'Total Debit', 'default' => false, 'value' => fn ($r) => round((float) $r->total_debit, 2)],
            'total_credit' => ['label' => 'Total Kredit', 'default' => false, 'value' => fn ($r) => round((float) $r->total_credit, 2)],
        ];
    }

    private function plExport(array $data, array $cols, array $f, ?array $selected): BinaryFileResponse
    {
        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $rows = ColumnPicker::rowsToArray($data['accounts'], $extractors);

        return (new ReportExcelExporter)
            ->addSheet('Detail Akun', $labels, $rows)
            ->addSheet('Ringkasan', ['Item', 'Nilai'], [
                ['Pendapatan', round($data['totals']['revenue'], 2)],
                ['HPP', round($data['totals']['cogs'], 2)],
                ['Laba Kotor', round($data['totals']['gross_profit'], 2)],
                ['Beban Operasional', round($data['totals']['expense'], 2)],
                ['Laba Bersih (kotor − beban)', round($data['totals']['net_profit'], 2)],
                ['Periode', $f['from']->toDateString().' s/d '.$f['to']->toDateString()],
                ['CATATAN', 'v1: Beban operasional (6xxx) belum termasuk biaya yang diinput manual — modul input beban menyusul (Batch B). Laporan ini = Laba Kotor.'],
            ])
            ->download('laba-rugi_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
    }

    // ───────────────────────── Neraca ─────────────────────────

    public function balanceSheet(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request, defaultFrom: false);

        $data = $this->bsRows($f['to']);
        $cols = $this->columnsBs();

        if ($request->boolean('export')) {
            return $this->bsExport($data, $cols, $f, $this->selectedColumns($request));
        }

        return Inertia::render('Reports/Financial/BalanceSheet', [
            'filters' => $this->filtersOut($f),
            'rows' => $data['accounts'],
            'totals' => $data['totals'],
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    private function columnsBs(): array
    {
        return [
            'code' => ['label' => 'Kode Akun', 'default' => true, 'value' => fn ($r) => $r->code],
            'name' => ['label' => 'Nama Akun', 'default' => true, 'value' => fn ($r) => $r->name],
            'type' => ['label' => 'Tipe', 'default' => true, 'value' => fn ($r) => $r->type],
            'saldo' => ['label' => 'Saldo', 'default' => true, 'value' => fn ($r) => round((float) $r->saldo, 2)],
            'normal_balance' => ['label' => 'NB', 'default' => false, 'value' => fn ($r) => $r->normal_balance],
            'total_debit' => ['label' => 'Total Debit', 'default' => false, 'value' => fn ($r) => round((float) $r->total_debit, 2)],
            'total_credit' => ['label' => 'Total Kredit', 'default' => false, 'value' => fn ($r) => round((float) $r->total_credit, 2)],
        ];
    }

    private function bsExport(array $data, array $cols, array $f, ?array $selected): BinaryFileResponse
    {
        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $rows = ColumnPicker::rowsToArray($data['accounts'], $extractors);

        return (new ReportExcelExporter)
            ->addSheet('Detail Akun', $labels, $rows)
            ->addSheet('Ringkasan', ['Item', 'Nilai'], [
                ['Total Aset', round($data['totals']['asset'], 2)],
                ['Total Kewajiban', round($data['totals']['liability'], 2)],
                ['Total Ekuitas', round($data['totals']['equity'], 2)],
                ['Laba Berjalan', round($data['totals']['current_pl'], 2)],
                ['Total Kewajiban + Ekuitas + Laba Berjalan', round($data['totals']['total_le'], 2)],
                ['Balanced?', $data['totals']['is_balanced'] ? 'YA' : 'TIDAK SEIMBANG'],
                ['Per Tanggal', $f['to']->toDateString()],
            ])
            ->download('neraca_'.$f['to']->format('Ymd').'.xlsx');
    }

    // ───────────────────────── Buku Besar ─────────────────────────

    public function generalLedger(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request);
        $coaId = $request->integer('coa_id');

        $accounts = Coa::orderBy('code')->get(['id', 'code', 'name', 'type', 'normal_balance']);
        $account = $coaId ? $accounts->firstWhere('id', $coaId) : null;

        $rows = [];
        $opening = 0.0;
        $closing = 0.0;
        if ($account) {
            $opening = (float) $this->balanceAsOf($account->id, $f['from']->copy()->subDay(), $account->normal_balance);

            $rows = DB::table('journal_entries as je')
                ->join('journals as j', 'j.id', '=', 'je.journal_id')
                ->where('je.coa_id', $account->id)
                ->where('j.status', 'posted')
                ->whereBetween('j.date', [$f['from']->toDateString(), $f['to']->toDateString()])
                ->orderBy('j.date')
                ->orderBy('j.id')
                ->orderBy('je.id')
                ->get([
                    'j.id as journal_id',
                    'j.date',
                    'j.journal_no',
                    'j.description',
                    'j.ref_type',
                    'j.ref_id',
                    'je.debit',
                    'je.credit',
                    'je.description as entry_description',
                ])
                ->all();

            $running = $opening;
            $isDebitNB = $account->normal_balance === 'debit';
            foreach ($rows as $r) {
                $delta = $isDebitNB
                    ? ((float) $r->debit - (float) $r->credit)
                    : ((float) $r->credit - (float) $r->debit);
                $running += $delta;
                $r->running_balance = $running;
            }
            $closing = $running;
        }

        $cols = $this->columnsGl();

        if ($request->boolean('export') && $account) {
            return $this->glExport($account, $rows, $opening, $closing, $cols, $f, $this->selectedColumns($request));
        }

        return Inertia::render('Reports/Financial/GeneralLedger', [
            'filters' => array_merge($this->filtersOut($f), ['coa_id' => $account?->id]),
            'accounts' => $accounts,
            'account' => $account,
            'opening' => $opening,
            'closing' => $closing,
            'rows' => $rows,
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    private function columnsGl(): array
    {
        return [
            'date' => ['label' => 'Tanggal', 'default' => true, 'value' => fn ($r) => $r->date],
            'journal_no' => ['label' => 'No Jurnal', 'default' => true, 'value' => fn ($r) => $r->journal_no],
            'description' => ['label' => 'Keterangan', 'default' => true, 'value' => fn ($r) => $r->entry_description ?? $r->description],
            'ref_type' => ['label' => 'Ref Type', 'default' => false, 'value' => fn ($r) => $r->ref_type ?? ''],
            'ref_id' => ['label' => 'Ref ID', 'default' => false, 'value' => fn ($r) => $r->ref_id ?? ''],
            'debit' => ['label' => 'Debit', 'default' => true, 'value' => fn ($r) => round((float) $r->debit, 2)],
            'credit' => ['label' => 'Kredit', 'default' => true, 'value' => fn ($r) => round((float) $r->credit, 2)],
            'running_balance' => ['label' => 'Saldo Berjalan', 'default' => true, 'value' => fn ($r) => round((float) ($r->running_balance ?? 0), 2)],
        ];
    }

    private function glExport(Coa $account, array $rows, float $opening, float $closing, array $cols, array $f, ?array $selected): BinaryFileResponse
    {
        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $data = ColumnPicker::rowsToArray($rows, $extractors);

        return (new ReportExcelExporter)
            ->addSheet('Buku Besar', $labels, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Akun', $account->code.' — '.$account->name],
                ['Tipe', $account->type],
                ['Saldo Normal', $account->normal_balance],
                ['Periode', $f['from']->toDateString().' s/d '.$f['to']->toDateString()],
                ['Saldo Awal', round($opening, 2)],
                ['Saldo Akhir', round($closing, 2)],
            ])
            ->download('buku-besar_'.$account->code.'_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
    }

    // ───────────────────────── Trial Balance ─────────────────────────

    public function trialBalance(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request);

        $rows = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->join('coa', 'coa.id', '=', 'je.coa_id')
            ->where('j.status', 'posted')
            ->whereBetween('j.date', [$f['from']->toDateString(), $f['to']->toDateString()])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type', 'coa.normal_balance')
            ->orderBy('coa.code')
            ->selectRaw('coa.id, coa.code, coa.name, coa.type, coa.normal_balance, '
                .'COALESCE(SUM(je.debit),0) as total_debit, '
                .'COALESCE(SUM(je.credit),0) as total_credit')
            ->get()
            ->map(function ($r) {
                $debit = (float) $r->total_debit;
                $credit = (float) $r->total_credit;
                $r->total_debit = $debit;
                $r->total_credit = $credit;
                $r->saldo = $r->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;

                return $r;
            })
            ->all();

        $sumDebit = array_sum(array_map(fn ($r) => $r->total_debit, $rows));
        $sumCredit = array_sum(array_map(fn ($r) => $r->total_credit, $rows));
        $cols = $this->columnsTb();

        if ($request->boolean('export')) {
            return $this->tbExport($rows, $sumDebit, $sumCredit, $cols, $f, $this->selectedColumns($request));
        }

        return Inertia::render('Reports/Financial/TrialBalance', [
            'filters' => $this->filtersOut($f),
            'rows' => $rows,
            'totals' => [
                'debit' => $sumDebit,
                'credit' => $sumCredit,
                'balanced' => abs($sumDebit - $sumCredit) < 0.01,
            ],
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    private function columnsTb(): array
    {
        return [
            'code' => ['label' => 'Kode', 'default' => true, 'value' => fn ($r) => $r->code],
            'name' => ['label' => 'Nama Akun', 'default' => true, 'value' => fn ($r) => $r->name],
            'type' => ['label' => 'Tipe', 'default' => true, 'value' => fn ($r) => $r->type],
            'normal_balance' => ['label' => 'NB', 'default' => false, 'value' => fn ($r) => $r->normal_balance],
            'total_debit' => ['label' => 'Total Debit', 'default' => true, 'value' => fn ($r) => round((float) $r->total_debit, 2)],
            'total_credit' => ['label' => 'Total Kredit', 'default' => true, 'value' => fn ($r) => round((float) $r->total_credit, 2)],
            'saldo' => ['label' => 'Saldo', 'default' => true, 'value' => fn ($r) => round((float) $r->saldo, 2)],
        ];
    }

    private function tbExport(array $rows, float $sumDebit, float $sumCredit, array $cols, array $f, ?array $selected): BinaryFileResponse
    {
        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $data = ColumnPicker::rowsToArray($rows, $extractors);

        return (new ReportExcelExporter)
            ->addSheet('Trial Balance', $labels, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Periode', $f['from']->toDateString().' s/d '.$f['to']->toDateString()],
                ['Total Debit', round($sumDebit, 2)],
                ['Total Kredit', round($sumCredit, 2)],
                ['Balance check', abs($sumDebit - $sumCredit) < 0.01 ? 'BALANCE' : 'TIDAK BALANCE'],
            ])
            ->download('trial-balance_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
    }

    // ───────────────────────── Jurnal Umum ─────────────────────────

    public function journalLog(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request);

        $q = Journal::query()
            ->where('status', 'posted')
            ->whereBetween('date', [$f['from']->toDateString(), $f['to']->toDateString()])
            ->orderBy('date')
            ->orderBy('id')
            ->with(['entries' => fn ($qq) => $qq->orderBy('id'), 'entries.coa:id,code,name']);

        $cols = $this->columnsJournalLog();

        if ($request->boolean('export')) {
            $journals = $q->get();
            // Flatten ke 1 row per entry.
            $flat = [];
            foreach ($journals as $j) {
                foreach ($j->entries as $e) {
                    $flat[] = (object) [
                        'date' => $j->date?->toDateString() ?? '',
                        'journal_no' => $j->journal_no,
                        'description' => $j->description,
                        'ref_type' => $j->ref_type,
                        'ref_id' => $j->ref_id,
                        'coa_code' => $e->coa->code ?? '',
                        'coa_name' => $e->coa->name ?? '',
                        'debit' => (float) $e->debit,
                        'credit' => (float) $e->credit,
                        'entry_description' => $e->description,
                    ];
                }
            }

            [$labels, $extractors] = ColumnPicker::pick($cols, $this->selectedColumns($request));
            $data = ColumnPicker::rowsToArray($flat, $extractors);

            return (new ReportExcelExporter)
                ->addSheet('Jurnal Umum', $labels, $data)
                ->download('jurnal-umum_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
        }

        return Inertia::render('Reports/Financial/JournalLog', [
            'filters' => $this->filtersOut($f),
            'journals' => $q->paginate(50)->withQueryString(),
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    private function columnsJournalLog(): array
    {
        return [
            'date' => ['label' => 'Tanggal', 'default' => true, 'value' => fn ($r) => $r->date],
            'journal_no' => ['label' => 'No Jurnal', 'default' => true, 'value' => fn ($r) => $r->journal_no],
            'description' => ['label' => 'Deskripsi Jurnal', 'default' => true, 'value' => fn ($r) => $r->description],
            'ref_type' => ['label' => 'Ref Type', 'default' => false, 'value' => fn ($r) => $r->ref_type ?? ''],
            'ref_id' => ['label' => 'Ref ID', 'default' => false, 'value' => fn ($r) => $r->ref_id ?? ''],
            'coa_code' => ['label' => 'Kode Akun', 'default' => true, 'value' => fn ($r) => $r->coa_code],
            'coa_name' => ['label' => 'Nama Akun', 'default' => true, 'value' => fn ($r) => $r->coa_name],
            'debit' => ['label' => 'Debit', 'default' => true, 'value' => fn ($r) => round((float) $r->debit, 2)],
            'credit' => ['label' => 'Kredit', 'default' => true, 'value' => fn ($r) => round((float) $r->credit, 2)],
            'entry_description' => ['label' => 'Deskripsi Entry', 'default' => false, 'value' => fn ($r) => $r->entry_description ?? ''],
        ];
    }

    // ───────────────────────── shared helpers ─────────────────────────

    /**
     * @return array<int, string>|null
     */
    private function selectedColumns(Request $request): ?array
    {
        $cols = $request->input('columns');
        if (! is_array($cols)) {
            return null;
        }

        return array_values(array_filter($cols, fn ($v) => is_string($v) && $v !== ''));
    }

    /**
     * @return array{accounts: list<object>, totals: array{revenue:float,cogs:float,gross_profit:float,expense:float,net_profit:float}}
     */
    private function plRows(CarbonImmutable $from, CarbonImmutable $to, ?int $warehouseId): array
    {
        // Warehouse filter belum diaplikasikan ke P&L (jurnal engine tidak simpan
        // warehouse_id di journal level). v1 konsolidasi.
        unset($warehouseId);

        $rows = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->join('coa', 'coa.id', '=', 'je.coa_id')
            ->where('j.status', 'posted')
            ->whereBetween('j.date', [$from->toDateString(), $to->toDateString()])
            ->whereIn('coa.type', ['revenue', 'cogs', 'expense'])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type', 'coa.normal_balance')
            ->orderBy('coa.code')
            ->selectRaw('coa.id, coa.code, coa.name, coa.type, coa.normal_balance, '
                .'COALESCE(SUM(je.debit),0) as total_debit, '
                .'COALESCE(SUM(je.credit),0) as total_credit')
            ->get()
            ->map(function ($r) {
                $debit = (float) $r->total_debit;
                $credit = (float) $r->total_credit;
                $r->total_debit = $debit;
                $r->total_credit = $credit;
                // revenue (apapun NB) → credit-debit; 4199 contra (NB=debit, debit value
                // di pos diskon) jadi negatif → mengurangi revenue saat SUM.
                $r->amount = $r->type === 'revenue'
                    ? $credit - $debit
                    : $debit - $credit;

                return $r;
            })
            ->all();

        $revenue = 0.0;
        $cogs = 0.0;
        $expense = 0.0;
        foreach ($rows as $r) {
            if ($r->type === 'revenue') {
                $revenue += $r->amount;
            } elseif ($r->type === 'cogs') {
                $cogs += $r->amount;
            } elseif ($r->type === 'expense') {
                $expense += $r->amount;
            }
        }
        $gross = $revenue - $cogs;
        $net = $gross - $expense;

        return [
            'accounts' => $rows,
            'totals' => [
                'revenue' => $revenue,
                'cogs' => $cogs,
                'gross_profit' => $gross,
                'expense' => $expense,
                'net_profit' => $net,
            ],
        ];
    }

    /**
     * @return array{accounts: list<object>, totals: array{asset:float,liability:float,equity:float,current_pl:float,total_le:float,is_balanced:bool}}
     */
    private function bsRows(CarbonImmutable $cutoff): array
    {
        $rows = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->join('coa', 'coa.id', '=', 'je.coa_id')
            ->where('j.status', 'posted')
            ->where('j.date', '<=', $cutoff->toDateString())
            ->whereIn('coa.type', ['asset', 'liability', 'equity'])
            ->groupBy('coa.id', 'coa.code', 'coa.name', 'coa.type', 'coa.normal_balance')
            ->orderBy('coa.code')
            ->selectRaw('coa.id, coa.code, coa.name, coa.type, coa.normal_balance, '
                .'COALESCE(SUM(je.debit),0) as total_debit, '
                .'COALESCE(SUM(je.credit),0) as total_credit')
            ->get()
            ->map(function ($r) {
                $debit = (float) $r->total_debit;
                $credit = (float) $r->total_credit;
                $r->total_debit = $debit;
                $r->total_credit = $credit;
                $r->saldo = $r->normal_balance === 'debit' ? $debit - $credit : $credit - $debit;

                return $r;
            })
            ->all();

        $asset = 0.0;
        $liability = 0.0;
        $equity = 0.0;
        foreach ($rows as $r) {
            if ($r->type === 'asset') {
                $asset += $r->saldo;
            } elseif ($r->type === 'liability') {
                $liability += $r->saldo;
            } elseif ($r->type === 'equity') {
                $equity += $r->saldo;
            }
        }

        // Laba berjalan = Σ revenue − Σ cogs − Σ expense dari awal sampai cutoff.
        // Revenue (apapun NB) → credit-debit; cogs/expense → -(debit-credit).
        $plToDate = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->join('coa', 'coa.id', '=', 'je.coa_id')
            ->where('j.status', 'posted')
            ->where('j.date', '<=', $cutoff->toDateString())
            ->whereIn('coa.type', ['revenue', 'cogs', 'expense'])
            ->selectRaw("SUM(CASE WHEN coa.type='revenue' THEN je.credit - je.debit "
                ."WHEN coa.type IN ('cogs','expense') THEN -(je.debit - je.credit) "
                ."ELSE 0 END) as plamt")
            ->first();
        $currentPl = (float) ($plToDate->plamt ?? 0);

        $totalLE = $liability + $equity + $currentPl;

        return [
            'accounts' => $rows,
            'totals' => [
                'asset' => $asset,
                'liability' => $liability,
                'equity' => $equity,
                'current_pl' => $currentPl,
                'total_le' => $totalLE,
                'is_balanced' => abs($asset - $totalLE) < 0.01,
            ],
        ];
    }

    private function balanceAsOf(int $coaId, CarbonImmutable $cutoff, string $normalBalance): float
    {
        $r = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->where('je.coa_id', $coaId)
            ->where('j.status', 'posted')
            ->where('j.date', '<=', $cutoff->toDateString())
            ->selectRaw('COALESCE(SUM(je.debit),0) as d, COALESCE(SUM(je.credit),0) as c')
            ->first();

        $d = (float) ($r->d ?? 0);
        $c = (float) ($r->c ?? 0);

        return $normalBalance === 'debit' ? $d - $c : $c - $d;
    }

    /**
     * @return array{from:CarbonImmutable,to:CarbonImmutable,warehouse_id:?int}
     */
    private function parsePeriod(Request $request, bool $defaultFrom = true): array
    {
        $now = CarbonImmutable::now();
        $from = $request->date('from')
            ? CarbonImmutable::parse($request->date('from'))->startOfDay()
            : ($defaultFrom ? $now->startOfMonth() : $now->startOfDay());
        $to = $request->date('to')
            ? CarbonImmutable::parse($request->date('to'))->endOfDay()
            : $now->endOfDay();

        if ($to->lt($from)) {
            $to = $from->endOfDay();
        }

        $whId = $request->integer('warehouse_id') ?: null;

        return ['from' => $from, 'to' => $to, 'warehouse_id' => $whId];
    }

    private function filtersOut(array $f): array
    {
        return [
            'from' => $f['from']->toDateString(),
            'to' => $f['to']->toDateString(),
            'warehouse_id' => $f['warehouse_id'] ?? null,
        ];
    }

    private function warehousesList()
    {
        return Warehouse::active()->orderBy('name')->get(['id', 'code', 'name']);
    }
}
