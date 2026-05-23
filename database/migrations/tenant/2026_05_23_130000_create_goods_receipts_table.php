<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('gr_no')->unique();
            $table->foreignId('po_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->date('received_at');
            $table->foreignId('received_by')->constrained('users');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();

            $table->index('po_id');
            $table->index(['warehouse_id', 'received_at']);
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gr_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->foreignId('po_item_id')->constrained('purchase_order_items');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('unit_id')->constrained('master_units');
            $table->decimal('qty_received', 14, 4);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
    }
};
