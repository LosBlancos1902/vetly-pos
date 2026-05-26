<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Coa;
use App\Services\Reports\ReportExcelExporter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Laporan Kas & Bank READ-ONLY.
 *
 * Mutasi: untuk akun di hirarki "Kas" (parent code 1100) — saat ini
 * meliputi 1101 Kas Besar, 1102 Kas Kecil, 1103 Bank BCA, 1104 Bank Mandiri,
 * 1105 Piutang QRIS. Hanya akun yang punya entry yang ditampilkan.
 *
 * Laporan Shift Kasir: ringkasan opening/expected/closing/variance.
 */
class CashBankReportController extends Controller
{
    /**
     * Daftar akun kas/bank + saldo per cutoff (sidebar).
     */
    public function index(Request $request): Response|BinaryFileResponse
    {
        $this->authorize('reports.financial.view');
        $f = $this->parsePeriod($request);

        // Cari akun child dari parent code "1100" (Kas).
        $parent = Coa::where('code', '1100')->first();
        $accounts = $parent
            ? Coa::where('parent_id', $parent->id)->orderBy('code')->get(['id', 'code', 'name', 'normal_balance'])
            : collect();

        // Saldo per akun di akhir periode (untuk dashboard cards)
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

        if ($coaId) {
            $account = $accounts->firstWhere('id', $coaId);
            if ($account) {
                // Opening = saldo sampai (from - 1 hari)
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

        if ($request->boolean('export') && $coaId) {
            $account = $accounts->firstWhere('id', $coaId);

            return $this->exportCashBank($account, $rows, $opening, $closing, $totalIn, $totalOut, $f);
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
        ]);
    }

    /**
     * Laporan Shift Kasir — list shift dengan opening/expected/closing/variance.
     */
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

        if ($request->boolean('export')) {
            return (new ReportExcelExporter)
                ->addSheet('Shift Kasir', [
                    'ID', 'Kasir', 'Cabang', 'Buka', 'Tutup',
                    'Kas Awal', 'Kas Harapan', 'Kas Tutup', 'Selisih', 'Status',
                ], array_map(fn ($r) => [
                    $r->id, $r->cashier_name, ($r->warehouse_code ?? '').' '.($r->warehouse_name ?? ''),
                    $r->opened_at, $r->closed_at ?? '',
                    $r->opening_cash, $r->expected_cash ?? '', $r->closing_cash ?? '',
                    $r->cash_variance ?? '', $r->status,
                ], $rows))
                ->download('shift-kasir_'.$f['from']->format('Ymd').'_'.$f['to']->format('Ymd').'.xlsx');
        }

        return Inertia::render('Reports/CashBank/Shifts', [
            'filters' => [
                'from' => $f['from']->toDateString(),
                'to' => $f['to']->toDateString(),
            ],
            'rows' => $rows,
        ]);
    }

    // ───────────────────────── helpers ─────────────────────────

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
        ?Coa $account,
        array $rows,
        float $opening,
        float $closing,
        float $totalIn,
        float $totalOut,
        array $f,
    ): BinaryFileResponse {
        if (! $account) {
            return (new ReportExcelExporter)
                ->addSheet('Kosong', ['Info'], [['Akun tidak ditemukan']])
                ->download('kas-bank.xlsx');
        }

        $data = [['', '', '(SALDO AWAL)', '', '', 0, 0, round($opening, 2)]];
        foreach ($rows as $r) {
            $data[] = [
                $r->date,
                $r->journal_no,
                $r->description,
                $r->ref_type ?? '',
                $r->entry_description ?? '',
                round((float) $r->masuk, 2),
                round((float) $r->keluar, 2),
                round((float) $r->saldo, 2),
            ];
        }
        $data[] = ['', '', '(SALDO AKHIR)', '', '',
            round($totalIn, 2), round($totalOut, 2), round($closing, 2)];

        return (new ReportExcelExporter)
            ->addSheet('Mutasi Kas/Bank', [
                'Tanggal', 'No Jurnal', 'Keterangan', 'Ref', 'Detail Entry',
                'Masuk', 'Keluar', 'Saldo',
            ], $data)
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
