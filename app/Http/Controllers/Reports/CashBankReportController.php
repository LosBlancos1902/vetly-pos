<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Coa;
use App\Services\Reports\ColumnPicker;
use App\Services\Reports\ReportExcelExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cash & Bank reports READ-ONLY dengan column picker.
 */
class CashBankReportController extends Controller
{
    public function index(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request);

        $parent = Coa::where('code', '1100')->first();
        $accounts = $parent
            ? Coa::where('parent_id', $parent->id)->orderBy('code')->get(['id', 'code', 'name', 'normal_balance'])
            : collect();

        $balances = [];
        foreach ($accounts as $a) {
            $r = DB::table('journal_entries as je')
                ->join('journals as j', 'j.id', '=', 'je.journal_id')
                ->where('je.coa_id', $a->id)
                ->where('j.status', 'posted')
                ->where('j.date', '<=', $f['to']->toDateString())
                ->selectRaw('COALESCE(SUM(je.debit),0) as d, COALESCE(SUM(je.credit),0) as c')
                ->first();
            $d = (float) ($r->d ?? 0);
            $c = (float) ($r->c ?? 0);
            $balances[$a->id] = $a->normal_balance === 'debit' ? $d - $c : $c - $d;
        }

        $coaId = $request->integer('coa_id') ?: ($accounts->first()->id ?? null);
        $rows = [];
        $opening = 0.0;
        $closing = 0.0;
        $totalIn = 0.0;
        $totalOut = 0.0;
        $account = null;

        if ($coaId) {
            $account = $accounts->firstWhere('id', $coaId);
            if ($account) {
                $openR = DB::table('journal_entries as je')
                    ->join('journals as j', 'j.id', '=', 'je.journal_id')
                    ->where('je.coa_id', $account->id)
                    ->where('j.status', 'posted')
                    ->where('j.date', '<', $f['from']->toDateString())
                    ->selectRaw('COALESCE(SUM(je.debit),0) as d, COALESCE(SUM(je.credit),0) as c')
                    ->first();
                $opening = $account->normal_balance === 'debit'
                    ? ((float) $openR->d - (float) $openR->c)
                    : ((float) $openR->c - (float) $openR->d);

                $entries = DB::table('journal_entries as je')
                    ->join('journals as j', 'j.id', '=', 'je.journal_id')
                    ->where('je.coa_id', $account->id)
                    ->where('j.status', 'posted')
                    ->whereBetween('j.date', [$f['from']->toDateString(), $f['to']->toDateString()])
                    ->orderBy('j.date')
                    ->orderBy('j.id')
                    ->orderBy('je.id')
                    ->get([
                        'j.id as journal_id', 'j.date', 'j.journal_no',
                        'j.description', 'j.ref_type', 'j.ref_id',
                        'je.debit', 'je.credit', 'je.description as entry_description',
                    ]);

                $running = $opening;
                $isDebitNB = $account->normal_balance === 'debit';
                foreach ($entries as $e) {
                    $debit = (float) $e->debit;
                    $credit = (float) $e->credit;
                    $masuk = $isDebitNB ? $debit : $credit;
                    $keluar = $isDebitNB ? $credit : $debit;
                    $totalIn += $masuk;
                    $totalOut += $keluar;
                    $running += $masuk - $keluar;
                    $e->masuk = $masuk;
                    $e->keluar = $keluar;
                    $e->saldo = $running;
                    $rows[] = $e;
                }
                $closing = $running;
            }
        }

        $cols = $this->columnsCashBank();

        if ($request->boolean('export') && $account) {
            return $this->exportCashBank($account, $rows, $opening, $closing, $totalIn, $totalOut, $cols, $f, $this->selectedColumns($request));
        }

        return Inertia::render('Reports/CashBank/Index', [
            'filters' => [
                'from' => $f['from']->toDateString(),
                'to' => $f['to']->toDateString(),
                'coa_id' => $coaId,
            ],
            'accounts' => $accounts->values(),
            'balances' => $balances,
            'rows' => $rows,
            'totals' => [
                'opening' => $opening,
                'in' => $totalIn,
                'out' => $totalOut,
                'closing' => $closing,
            ],
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    public function shifts(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request);

        $rows = DB::table('shifts as sh')
            ->join('users as u', 'u.id', '=', 'sh.cashier_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'sh.warehouse_id')
            ->whereBetween('sh.opened_at', [$f['from'], $f['to']])
            ->orderBy('sh.opened_at', 'desc')
            ->get([
                'sh.id', 'sh.opened_at', 'sh.closed_at',
                'sh.opening_cash', 'sh.expected_cash', 'sh.closing_cash', 'sh.cash_variance',
                'sh.status',
                'u.name as cashier_name',
                'w.code as warehouse_code', 'w.name as warehouse_name',
            ])
            ->map(fn ($r) => (object) [
                'id' => (int) $r->id,
                'opened_at' => $r->opened_at,
                'closed_at' => $r->closed_at,
                'cashier_name' => $r->cashier_name,
                'warehouse_code' => $r->warehouse_code,
                'warehouse_name' => $r->warehouse_name,
                'opening_cash' => (float) $r->opening_cash,
                'expected_cash' => $r->expected_cash !== null ? (float) $r->expected_cash : null,
                'closing_cash' => $r->closing_cash !== null ? (float) $r->closing_cash : null,
                'cash_variance' => $r->cash_variance !== null ? (float) $r->cash_variance : null,
                'status' => $r->status,
            ])
            ->all();

        $cols = $this->columnsShifts();

        if ($request->boolean('export')) {
            [$labels, $extractors] = ColumnPicker::pick($cols, $this->selectedColumns($request));
            $data = ColumnPicker::rowsToArray($rows, $extractors);

            return (new ReportExcelExporter)
                ->addSheet('Shift Kasir', $labels, $data)
                ->download('shift-kasir_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
        }

        return Inertia::render('Reports/CashBank/Shifts', [
            'filters' => [
                'from' => $f['from']->toDateString(),
                'to' => $f['to']->toDateString(),
            ],
            'rows' => $rows,
            'available_columns' => ColumnPicker::publicMeta($cols),
        ]);
    }

    // ───────────────────────── columns ─────────────────────────

    private function columnsCashBank(): array
    {
        return [
            'date' => ['label' => 'Tanggal', 'default' => true, 'value' => fn ($r) => $r->date],
            'journal_no' => ['label' => 'No Jurnal', 'default' => true, 'value' => fn ($r) => $r->journal_no],
            'description' => ['label' => 'Keterangan', 'default' => true, 'value' => fn ($r) => $r->entry_description ?? $r->description],
            'ref_type' => ['label' => 'Ref Type', 'default' => false, 'value' => fn ($r) => $r->ref_type ?? ''],
            'ref_id' => ['label' => 'Ref ID', 'default' => false, 'value' => fn ($r) => $r->ref_id ?? ''],
            'entry_description' => ['label' => 'Detail Entry', 'default' => false, 'value' => fn ($r) => $r->entry_description ?? ''],
            'masuk' => ['label' => 'Masuk', 'default' => true, 'value' => fn ($r) => (float) $r->masuk],
            'keluar' => ['label' => 'Keluar', 'default' => true, 'value' => fn ($r) => (float) $r->keluar],
            'saldo' => ['label' => 'Saldo', 'default' => true, 'value' => fn ($r) => (float) $r->saldo],
        ];
    }

    private function columnsShifts(): array
    {
        return [
            'id' => ['label' => 'ID', 'default' => false, 'value' => fn ($r) => $r->id],
            'cashier_name' => ['label' => 'Kasir', 'default' => true, 'value' => fn ($r) => $r->cashier_name],
            'warehouse_code' => ['label' => 'Kode Cabang', 'default' => false, 'value' => fn ($r) => $r->warehouse_code ?? ''],
            'warehouse_name' => ['label' => 'Cabang', 'default' => true, 'value' => fn ($r) => $r->warehouse_name ?? ''],
            'opened_at' => ['label' => 'Buka', 'default' => true, 'value' => fn ($r) => $r->opened_at],
            'closed_at' => ['label' => 'Tutup', 'default' => true, 'value' => fn ($r) => $r->closed_at ?? ''],
            'opening_cash' => ['label' => 'Kas Awal', 'default' => true, 'value' => fn ($r) => $r->opening_cash],
            'expected_cash' => ['label' => 'Kas Harapan', 'default' => true, 'value' => fn ($r) => $r->expected_cash ?? ''],
            'closing_cash' => ['label' => 'Kas Tutup', 'default' => true, 'value' => fn ($r) => $r->closing_cash ?? ''],
            'cash_variance' => ['label' => 'Selisih', 'default' => true, 'value' => fn ($r) => $r->cash_variance ?? ''],
            'status' => ['label' => 'Status', 'default' => true, 'value' => fn ($r) => $r->status],
        ];
    }

    // ───────────────────────── helpers ─────────────────────────

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
     * @return array{from:CarbonImmutable,to:CarbonImmutable}
     */
    private function parsePeriod(Request $request): array
    {
        $now = CarbonImmutable::now();
        $from = $request->date('from')
            ? CarbonImmutable::parse($request->date('from'))->startOfDay()
            : $now->startOfMonth();
        $to = $request->date('to')
            ? CarbonImmutable::parse($request->date('to'))->endOfDay()
            : $now->endOfDay();
        if ($to->lt($from)) {
            $to = $from->endOfDay();
        }

        return ['from' => $from, 'to' => $to];
    }

    private function exportCashBank(
        Coa $account,
        array $rows,
        float $opening,
        float $closing,
        float $totalIn,
        float $totalOut,
        array $cols,
        array $f,
        ?array $selected,
    ): BinaryFileResponse {
        [$labels, $extractors] = ColumnPicker::pick($cols, $selected);
        $data = ColumnPicker::rowsToArray($rows, $extractors);

        return (new ReportExcelExporter)
            ->addSheet('Mutasi Kas/Bank', $labels, $data)
            ->addSheet('Info', ['Item', 'Nilai'], [
                ['Akun', $account->code.' — '.$account->name],
                ['Periode', $f['from']->toDateString().' s/d '.$f['to']->toDateString()],
                ['Saldo Awal', round($opening, 2)],
                ['Total Masuk', round($totalIn, 2)],
                ['Total Keluar', round($totalOut, 2)],
                ['Saldo Akhir', round($closing, 2)],
            ])
            ->download('kas-bank_'.$account->code.'_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
    }
}
