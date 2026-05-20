<?php

namespace App\Services;

use App\Models\Tenant\Coa;
use App\Models\Tenant\Journal;
use App\Models\Tenant\Sale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Auto-journaling. Every posted journal must balance:
 * sum(debit) === sum(credit), otherwise a RuntimeException is thrown.
 */
class JournalEngine
{
    /**
     * Sale:
     *   D 1101 Kas             = total
     *   D 4199 Diskon Penjualan = discount
     *   C 4101 Penjualan Retail = subtotal
     *   C 2102 Hutang Pajak     = tax
     *   D 5100 HPP              = COGS
     *   C 1201 Persediaan       = COGS
     */
    public function postSale(Sale $sale): Journal
    {
        $cogs = (float) $sale->items->sum(fn ($i) => (float) $i->cost_snapshot * (float) $i->qty);

        $lines = [
            ['1101', (float) $sale->total, 0.0],
            ['4199', (float) $sale->discount_amount, 0.0],
            ['4101', 0.0, (float) $sale->subtotal],
            ['2102', 0.0, (float) $sale->tax_amount],
            ['5100', $cogs, 0.0],
            ['1201', 0.0, $cogs],
        ];

        return $this->post(
            description: "Penjualan #{$sale->invoice_no}",
            refType: Sale::class,
            refId: $sale->id,
            lines: $lines,
        );
    }

    /**
     * Compound sale (racikan):
     *   D 1101 Kas             = total
     *   D 4199 Diskon Penjualan = discount
     *   C 4102 Penjualan Klinik = subtotal MINUS racik_fee_revenue
     *   C 4104 Pendapatan Jasa Racik = racik_fee_revenue (jika > 0)
     *   C 2102 Hutang Pajak     = tax
     *   D 5102 HPP Klinik       = COGS bahan
     *   C 1201 Persediaan Retail = COGS bahan
     *
     * `racikFeeRevenue` lets the caller split out racik service portion from
     * goods revenue. Pass 0 to fold everything into 4102.
     */
    public function postCompoundSale(
        string $ref,
        ?int $refId,
        float $total,
        float $subtotal,
        float $discount,
        float $tax,
        float $cogs,
        float $racikFeeRevenue = 0.0,
    ): Journal {
        $goodsRevenue = round($subtotal - $racikFeeRevenue, 2);

        $lines = [
            ['1101', $total, 0.0],
            ['4199', $discount, 0.0],
            ['4102', 0.0, $goodsRevenue],
            ['4104', 0.0, $racikFeeRevenue],
            ['2102', 0.0, $tax],
            ['5102', $cogs, 0.0],
            ['1201', 0.0, $cogs],
        ];

        return $this->post(
            description: "Penjualan racikan {$ref}",
            refType: 'compound_sale',
            refId: $refId,
            lines: $lines,
        );
    }

    /**
     * Service sale (jasa, dengan atau tanpa konsumsi bahan):
     *   D 1101 Kas            = total
     *   C 4103 Pendapatan Jasa = subtotal
     *   C 2102 Hutang Pajak    = tax
     *   D 5102 HPP Klinik      = cogs bahan (0 untuk service murni)
     *   C 1201 Persediaan      = cogs bahan
     */
    public function postServiceSale(
        string $ref,
        ?int $refId,
        float $total,
        float $subtotal,
        float $tax,
        float $cogsMaterials,
    ): Journal {
        $lines = [
            ['1101', $total, 0.0],
            ['4103', 0.0, $subtotal],
            ['2102', 0.0, $tax],
            ['5102', $cogsMaterials, 0.0],
            ['1201', 0.0, $cogsMaterials],
        ];

        return $this->post(
            description: "Tindakan jasa {$ref}",
            refType: 'service_sale',
            refId: $refId,
            lines: $lines,
        );
    }

    /**
     * Purchase / goods receipt:
     *   D 1201 Persediaan   = amount
     *   C 2101 Hutang Supplier = amount
     */
    public function postPurchase(string $ref, float $amount): Journal
    {
        return $this->post(
            description: "Pembelian {$ref}",
            refType: 'purchase',
            refId: null,
            lines: [
                ['1201', $amount, 0.0],
                ['2101', 0.0, $amount],
            ],
        );
    }

    /**
     * Stock opname / adjustment:
     *   plus  => D 1201 Persediaan / C 5100 HPP
     *   minus => D 5100 HPP        / C 1201 Persediaan
     */
    public function postAdjustment(string $ref, float $amount, bool $isPlus): Journal
    {
        $lines = $isPlus
            ? [['1201', $amount, 0.0], ['5100', 0.0, $amount]]
            : [['5100', $amount, 0.0], ['1201', 0.0, $amount]];

        return $this->post("Penyesuaian stok {$ref}", 'adjustment', null, $lines);
    }

    /**
     * @param  array<int, array{0:string,1:float,2:float}>  $lines  [coa_code, debit, credit]
     */
    private function post(string $description, string $refType, ?int $refId, array $lines): Journal
    {
        $totalDebit = round(array_sum(array_column($lines, 1)), 2);
        $totalCredit = round(array_sum(array_column($lines, 2)), 2);

        if ($totalDebit !== $totalCredit) {
            throw new RuntimeException(
                "Jurnal tidak balance: debit {$totalDebit} != credit {$totalCredit} ({$description})"
            );
        }

        return DB::transaction(function () use ($description, $refType, $refId, $lines) {
            $journal = Journal::create([
                'journal_no' => 'JRN-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'date' => now()->toDateString(),
                'ref_type' => $refType,
                'ref_id' => $refId,
                'description' => $description,
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => Auth::id(),
            ]);

            $coaIds = Coa::whereIn('code', array_column($lines, 0))->pluck('id', 'code');

            foreach ($lines as [$code, $debit, $credit]) {
                if (($debit + $credit) <= 0) {
                    continue; // skip zero lines
                }
                $journal->entries()->create([
                    'coa_id' => $coaIds[$code] ?? null,
                    'debit' => number_format($debit, 2, '.', ''),
                    'credit' => number_format($credit, 2, '.', ''),
                ]);
            }

            return $journal;
        });
    }
}
