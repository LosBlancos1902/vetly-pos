<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock transfer 2-step (Batch 3 gudang module).
 *
 * Flow:
 *   ship (status=in_transit) → receive (status=completed)
 *
 * Jurnal:
 *   ship    → D 1203 BDP / C 1201 Persediaan  (TOTAL_SENT = Σ qty_sent × cost_at_transfer)
 *   receive → D 1201 (tujuan) + D 5100 (loss) / C 1203 BDP
 *
 * Status 'cancelled' reserved untuk future cancel-before-ship (saat ini
 * ship=create jadi langsung in_transit, no draft).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $t) {
            $t->id();
            $t->string('transfer_no')->unique();
            $t->foreignId('source_warehouse_id')->constrained('warehouses');
            $t->foreignId('dest_warehouse_id')->constrained('warehouses');

            $t->enum('status', ['in_transit', 'completed', 'cancelled'])
                ->default('in_transit');

            $t->dateTime('shipped_at');
            $t->dateTime('received_at')->nullable();

            $t->foreignId('shipped_by')->constrained('users');
            $t->foreignId('received_by')->nullable()->constrained('users');

            $t->text('notes')->nullable();
            $t->text('receive_notes')->nullable();

            // Link audit trail bidirectional ke jurnal
            $t->foreignId('journal_ship_id')->nullable()
                ->constrained('journals')->nullOnDelete();
            $t->foreignId('journal_receive_id')->nullable()
                ->constrained('journals')->nullOnDelete();

            $t->timestamps();

            $t->index(['source_warehouse_id', 'dest_warehouse_id']);
            $t->index('status');
            $t->index('shipped_at');
        });

        Schema::create('stock_transfer_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('products');

            // BASE unit (konvensi engine StockMovement::record)
            $t->decimal('qty_sent', 15, 4);
            $t->decimal('qty_received', 15, 4)->nullable(); // null sampai TERIMA
            $t->decimal('cost_at_transfer', 15, 2);          // snapshot source.cost_avg

            $t->text('variance_notes')->nullable();

            $t->timestamps();

            $t->unique(['transfer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
