<?php

namespace App\Services;

use App\Models\Tenant\Sale;

/**
 * Renders an ESC/POS byte string for Bluetooth thermal printers.
 * The frontend fetches this payload and pushes it over Web Bluetooth.
 */
class ReceiptPrinter
{
    private const ESC = "\x1B";
    private const GS = "\x1D";

    /**
     * @param  '58mm'|'80mm'  $width
     */
    public function render(Sale $sale, string $width = '58mm'): string
    {
        $cols = $width === '80mm' ? 48 : 32;

        $sale->loadMissing(['items.product', 'payments', 'customer']);

        $out = self::ESC.'@';                 // initialize
        $out .= self::ESC.'a'.chr(1);         // center
        $out .= self::ESC.'!'.chr(0x18);      // double height/width
        $out .= "VETLY POS\n";
        $out .= self::ESC.'!'.chr(0);         // normal
        $out .= "Struk Penjualan\n";
        $out .= str_repeat('-', $cols)."\n";
        $out .= self::ESC.'a'.chr(0);         // left

        $out .= $this->line('No', $sale->invoice_no, $cols);
        $out .= $this->line('Tgl', optional($sale->date)->format('d/m/Y H:i'), $cols);
        $out .= $this->line('Kasir', (string) $sale->cashier_id, $cols);
        $out .= str_repeat('-', $cols)."\n";

        foreach ($sale->items as $item) {
            $name = $item->product->name ?? ('#'.$item->product_id);
            $out .= substr($name, 0, $cols)."\n";
            $qtyLine = rtrim(rtrim((string) $item->qty, '0'), '.')
                .' x '.number_format((float) $item->price, 0, ',', '.');
            $out .= $this->line($qtyLine, number_format((float) $item->subtotal, 0, ',', '.'), $cols);
        }

        $out .= str_repeat('-', $cols)."\n";
        $out .= $this->line('Subtotal', number_format((float) $sale->subtotal, 0, ',', '.'), $cols);
        $out .= $this->line('Diskon', number_format((float) $sale->discount_amount, 0, ',', '.'), $cols);
        $out .= $this->line('Pajak', number_format((float) $sale->tax_amount, 0, ',', '.'), $cols);
        $out .= self::ESC.'!'.chr(0x08);      // emphasized
        $out .= $this->line('TOTAL', number_format((float) $sale->total, 0, ',', '.'), $cols);
        $out .= self::ESC.'!'.chr(0);

        foreach ($sale->payments as $p) {
            $out .= $this->line(strtoupper($p->method), number_format((float) $p->amount, 0, ',', '.'), $cols);
        }

        $out .= "\n";
        $out .= self::ESC.'a'.chr(1);         // center
        $out .= "Terima kasih :)\n\n\n";
        $out .= self::GS.'V'.chr(66).chr(0);  // partial cut

        return $out;
    }

    private function line(string $left, ?string $right, int $cols): string
    {
        $right = (string) $right;
        $space = max(1, $cols - strlen($left) - strlen($right));

        return $left.str_repeat(' ', $space).$right."\n";
    }
}
