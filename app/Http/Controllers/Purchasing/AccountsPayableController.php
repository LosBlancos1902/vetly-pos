<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\Tenant\AccountsPayable;
use App\Models\Tenant\ApPayment;
use App\Models\Tenant\Coa;
use App\Services\JournalEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AccountsPayableController extends Controller
{
    public function __construct(
        private readonly JournalEngine $journal,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->authorize('purchasing.ap_view');

        $payables = AccountsPayable::query()
            ->with([
                'supplier:id,code,name',
                'goodsReceipt:id,gr_no,received_at',
                'purchaseOrder:id,po_no',
                'payments',
                'journal:id,journal_no',
            ])
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn ($q, $sid) => $q->where('supplier_id', $sid))
            ->orderBy('due_date')
            ->paginate(20)
            ->withQueryString();

        // Pass cash/bank COA pilihan untuk dropdown pelunasan.
        $cashCoas = Coa::whereIn('code', ['1101', '1102', '1103', '1104'])
            ->where('is_active', true)
            ->get(['code', 'name']);

        return Inertia::render('Purchasing/AccountsPayable', [
            'payables' => $payables,
            'filters' => $request->only('status', 'supplier_id'),
            'cashCoas' => $cashCoas,
        ]);
    }

    public function pay(Request $request, AccountsPayable $accountsPayable): RedirectResponse
    {
        $this->authorize('purchasing.ap_pay');

        if ($accountsPayable->status === AccountsPayable::STATUS_PAID) {
            abort(422, "Hutang {$accountsPayable->ap_no} sudah lunas.");
        }
        if ($accountsPayable->status === AccountsPayable::STATUS_VOID) {
            abort(422, "Hutang {$accountsPayable->ap_no} sudah void.");
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_coa_code' => ['required', 'string', 'in:1101,1102,1103,1104'],
            'paid_at' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $remaining = (float) bcsub(
            (string) $accountsPayable->amount,
            (string) $accountsPayable->paid_amount,
            2,
        );
        if ($data['amount'] > $remaining + 0.001) {
            abort(422, sprintf(
                "Pembayaran %s melebihi sisa hutang %s.",
                number_format($data['amount'], 2),
                number_format($remaining, 2),
            ));
        }

        DB::transaction(function () use ($accountsPayable, $data, $request) {
            // Post jurnal: D 2101 Hutang Supplier / C <payment_coa>.
            $journal = $this->journal->postApPayment(
                ref: $accountsPayable->ap_no,
                amount: (float) $data['amount'],
                cashCoaCode: $data['payment_coa_code'],
                refId: $accountsPayable->id,
            );

            ApPayment::create([
                'payment_no' => $this->generatePaymentNo(),
                'ap_id' => $accountsPayable->id,
                'amount' => $data['amount'],
                'payment_coa_code' => $data['payment_coa_code'],
                'paid_at' => $data['paid_at'],
                'paid_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
                'journal_id' => $journal->id,
            ]);

            // Akumulasi & flip status.
            $newPaid = bcadd(
                (string) $accountsPayable->paid_amount,
                (string) $data['amount'],
                2,
            );
            $isPaid = bccomp($newPaid, (string) $accountsPayable->amount, 2) >= 0;

            $accountsPayable->update([
                'paid_amount' => $newPaid,
                'status' => $isPaid
                    ? AccountsPayable::STATUS_PAID
                    : AccountsPayable::STATUS_PARTIALLY_PAID,
            ]);
        });

        return back()->with('success', "Pembayaran {$accountsPayable->ap_no} dicatat.");
    }

    private function generatePaymentNo(): string
    {
        $prefix = 'APP-'.now()->format('Ym').'-';
        $lastSeq = ApPayment::where('payment_no', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderBy('id', 'desc')
            ->value('payment_no');

        $next = $lastSeq ? ((int) substr($lastSeq, -4)) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
