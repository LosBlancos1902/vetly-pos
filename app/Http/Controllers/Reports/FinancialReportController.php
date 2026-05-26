<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Coa;
use App\Models\Tenant\Journal;
use App\Models\Tenant\Warehouse;
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
 * Aggregation di SQL, bukan PHP. Pakai index existing (journals.date,
 * journal_entries.coa_id). Tidak menulis apapun, tidak nyentuh engine.
 *
 * Catatan v1: P&L = Laba KOTOR (Pendapatan − HPP). Beban operasional
 * (6xxx) di-display tapi saat ini KOSONG karena belum ada modul input
 * beban manual (Batch B). Disclaimer ditampilkan eksplisit di UI.
 */
class FinancialReportController extends Controller
{
    /**
     * Laba Rugi (P&L) — agregat per akun revenue/cogs/expense dalam periode.
     */
    public function profitLoss(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request);

        $rows = $this->plRows($f['from'], $f['to'], $f['warehouse_id']);

        if ($request->boolean('export')) {
            return $this->plExport($rows, $f);
        }

        return Inertia::render('Reports/Financial/ProfitLoss', [
            'filters' => $this->filtersOut($f),
            'warehouses' => $this->warehousesList(),
            'rows' => $rows['accounts'],
            'totals' => $rows['totals'],
        ]);
    }

    /**
     * Neraca per tanggal cut-off — saldo asset/liability/equity dari awal
     * sampai $f['to'] (inclusive). Termasuk laba berjalan period-to-date.
     */
    public function balanceSheet(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request, defaultFrom: false);

        $rows = $this->bsRows($f['to']);

        if ($request->boolean('export')) {
            return $this->bsExport($rows, $f);
        }

        return Inertia::render('Reports/Financial/BalanceSheet', [
            'filters' => $this->filtersOut($f),
            'rows' => $rows['accounts'],
            'totals' => $rows['totals'],
        ]);
    }

    /**
     * Buku Besar per Akun — list movement satu akun dengan running balance.
     * Opening balance = saldo sampai (from - 1 hari).
     */
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

            // running balance — orient pada normal balance akun
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

        if ($request->boolean('export') && $account) {
            return $this->glExport($account, $rows, $opening, $closing, $f);
        }

        return Inertia::render('Reports/Financial/GeneralLedger', [
            'filters' => array_merge($this->filtersOut($f), ['coa_id' => $account?->id]),
            'accounts' => $accounts,
            'account' => $account,
            'opening' => $opening,
            'closing' => $closing,
            'rows' => $rows,
        ]);
    }

    /**
     * Trial Balance — total debit & credit per akun dalam periode + saldo akhir.
     * Total kolom debit HARUS = total kolom credit (sanity check engine).
     */
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

        if ($request->boolean('export')) {
            return $this->tbExport($rows, $sumDebit, $sumCredit, $f);
        }

        return Inertia::render('Reports/Financial/TrialBalance', [
            'filters' => $this->filtersOut($f),
            'rows' => $rows,
            'totals' => [
                'debit' => $sumDebit,
                'credit' => $sumCredit,
                'balanced' => abs($sumDebit - $sumCredit) < 0.01,
            ],
        ]);
    }

    /**
     * Jurnal Umum — list jurnal posted dalam periode beserta entries-nya.
     * Paginated supaya tidak OOM untuk periode panjang.
     */
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

        if ($request->boolean('export')) {
            // Untuk export: ambil semua (flat 1 row per entry, bukan per jurnal)
            $journals = $q->get();

            return $this->journalLogExport($journals, $f);
        }

        return Inertia::render('Reports/Financial/JournalLog', [
            'filters' => $this->filtersOut($f),
            'journals' => $q->paginate(50)->withQueryString(),
        ]);
    }

    // ───────────────────────── shared helpers ─────────────────────────

    /**
     * @return array{accounts: list<object>, totals: array{revenue:float,cogs:float,gross_profit:float,expense:float,net_profit:float}}
     */
    private function plRows(CarbonImmutable $from, CarbonImmutable $to, ?int $warehouseId): array
    {
        // NOTE warehouse filter di journal: belum semua jurnal punya warehouse_id
        // (engine post tidak simpan warehouse). Filter cabang di P&L jadi
        // dilakukan via JOIN ke ref source kalau memungkinkan — tapi untuk
        // v1 kita keep KONSOLIDASI (warehouse filter tidak diaplikasikan ke P&L).
        // UI ttep tampilkan dropdown tapi disable / abu-abu kalau dipilih.
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
                // Nilai untuk P&L:
                //   revenue (apapun NB): credit-debit → 4199 contra (debit di pos diskon)
                //     jadi NEGATIF, mengurangi total revenue saat di-SUM.
                //   cogs/expense (NB debit): debit-credit
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
        // Saldo per akun dari awal sampai cutoff (inclusive).
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
        $plToDate = DB::table('journal_entries as je')
            ->join('journals as j', 'j.id', '=', 'je.journal_id')
            ->join('coa', 'coa.id', '=', 'je.coa_id')
            ->where('j.status', 'posted')
            ->where('j.date', '<=', $cutoff->toDateString())
            ->whereIn('coa.type', ['revenue', 'cogs', 'expense'])
            // Revenue (apapun NB) di-sum credit-debit → 4199 contra (debit) jadi −,
            // mengurangi revenue. COGS/Expense (NB debit) di-sum debit-credit lalu
            // dikurangkan dari revenue → −(debit-credit).
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

    // ───────────────────────── exporters ─────────────────────────

    private function plExport(array $data, array $f): BinaryFileResponse
    {
        $exporter = new ReportExcelExporter;

        // Sheet 1: rekap per akun (flat tabular)
        $rows = [];
        foreach ($data['accounts'] as $r) {
            $rows[] = [
                $r->code,
                $r->name,
                $r->type,
                round((float) $r->amount, 2),
            ];
        }
        // Tambahan: ringkasan totals di SHEET TERPISAH (flat tetap)
        $exporter->addSheet('Detail Akun', ['Kode', 'Nama Akun', 'Tipe', 'Nilai'], $rows);
        $exporter->addSheet('Ringkasan', ['Item', 'Nilai'], [
            ['Pendapatan', round($data['totals']['revenue'], 2)],
            ['HPP', round($data['totals']['cogs'], 2)],
            ['Laba Kotor', round($data['totals']['gross_profit'], 2)],
            ['Beban Operasional', round($data['totals']['expense'], 2)],
            ['Laba Bersih (kotor − beban)', round($data['totals']['net_profit'], 2)],
            ['Periode', $f['from']->toDateString().' s/d '.$f['to']->toDateString()],
            ['CATATAN', 'Beban operasional (6xxx) belum termasuk biaya yang diinput manual — modul input beban menyusul (Batch B). v1 = Laba Kotor.'],
        ]);

        return $exporter->download('laba-rugi_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
    }

    private function bsExport(array $data, array $f): BinaryFileResponse
    {
        $rows = [];
        foreach ($data['accounts'] as $r) {
            $rows[] = [$r->code, $r->name, $r->type, round((float) $r->saldo, 2)];
        }

        $exporter = (new ReportExcelExporter)
            ->addSheet('Detail Akun', ['Kode', 'Nama Akun', 'Tipe', 'Saldo'], $rows)
            ->addSheet('Ringkasan', ['Item', 'Nilai'], [
                ['Total Aset', round($data['totals']['asset'], 2)],
                ['Total Kewajiban', round($data['totals']['liability'], 2)],
                ['Total Ekuitas', round($data['totals']['equity'], 2)],
                ['Laba Berjalan', round($data['totals']['current_pl'], 2)],
                ['Total Kewajiban + Ekuitas + Laba Berjalan', round($data['totals']['total_le'], 2)],
                ['Balanced?', $data['totals']['is_balanced'] ? 'YA' : 'TIDAK SEIMBANG'],
                ['Per Tanggal', $f['to']->toDateString()],
            ]);

        return $exporter->download('neraca_'.$f['to']->format('Ymd').'.xlsx');
    }

    private function glExport(Coa $account, array $rows, float $opening, float $closing, array $f): BinaryFileResponse
    {
        $data = [];
        $data[] = ['', '', '(SALDO AWAL)', '', '', '', round($opening, 2)];
        foreach ($rows as $r) {
            $data[] = [
                $r->date,
                $r->journal_no,
                $r->description,
                $r->ref_type ?? '',
                round((float) $r->debit, 2),
                round((float) $r->credit, 2),
                round((float) ($r->running_balance ?? 0), 2),
            ];
        }
        $data[] = ['', '', '(SALDO AKHIR)', '', '', '', round($closing, 2)];

        $exporter = (new ReportExcelExporter)
            ->addSheet('Buku Besar', ['Tanggal', 'No Jurnal', 'Keterangan', 'Ref', 'Debit', 'Kredit', 'Saldo'], $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Akun', $account->code.' — '.$account->name],
                ['Tipe', $account->type],
                ['Saldo Normal', $account->normal_balance],
                ['Periode', $f['from']->toDateString().' s/d '.$f['to']->toDateString()],
            ]);

        return $exporter->download('buku-besar_'.$account->code.'_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
    }

    private function tbExport(array $rows, float $sumDebit, float $sumCredit, array $f): BinaryFileResponse
    {
        $data = [];
        foreach ($rows as $r) {
            $data[] = [
                $r->code, $r->name, $r->type, $r->normal_balance,
                round((float) $r->total_debit, 2),
                round((float) $r->total_credit, 2),
                round((float) $r->saldo, 2),
            ];
        }
        $data[] = ['', '(TOTAL)', '', '', round($sumDebit, 2), round($sumCredit, 2), ''];

        return (new ReportExcelExporter)
            ->addSheet('Trial Balance',
                ['Kode', 'Nama Akun', 'Tipe', 'NB', 'Total Debit', 'Total Kredit', 'Saldo'],
                $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Periode', $f['from']->toDateString().' s/d '.$f['to']->toDateString()],
                ['Balance check', abs($sumDebit - $sumCredit) < 0.01 ? 'BALANCE' : 'TIDAK BALANCE'],
            ])
            ->download('trial-balance_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
    }

    private function journalLogExport($journals, array $f): BinaryFileResponse
    {
        // Flat: 1 baris per entry (journal_no di-repeat). Header journal + line.
        $data = [];
        foreach ($journals as $j) {
            foreach ($j->entries as $e) {
                $data[] = [
                    $j->date?->toDateString() ?? '',
                    $j->journal_no,
                    $j->description,
                    $j->ref_type ?? '',
                    (int) ($j->ref_id ?? 0),
                    $e->coa->code ?? '',
                    $e->coa->name ?? '',
                    round((float) $e->debit, 2),
                    round((float) $e->credit, 2),
                    $e->description ?? '',
                ];
            }
        }

        return (new ReportExcelExporter)
            ->addSheet('Jurnal Umum',
                ['Tanggal', 'No Jurnal', 'Deskripsi Jurnal', 'Ref Type', 'Ref ID',
                    'Kode Akun', 'Nama Akun', 'Debit', 'Kredit', 'Deskripsi Entry'],
                $data)
            ->download('jurnal-umum_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
    }
}
