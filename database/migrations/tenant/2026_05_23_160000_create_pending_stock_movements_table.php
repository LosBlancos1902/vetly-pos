<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending stock movements — sale/consumption events yang DITAHAN selama
 * SO aktif (warehouse + product yang lagi di-snap). Dikonsumsi (di-apply
 * ke real stock_movements + post HPP journal) saat SO complete.
 *
 * Bukan partial stock_movement: ini calon movement, audit trail terpisah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opname_id')->constrained('stock_opnames')->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->nullable()
                ->constrained('sales_items')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('warehouse_id')->constrained('warehouses');
            $table->enum('type', ['sale', 'service_consumption']);
            $table->decimal('qty_base', 15, 4);
            $table->decimal('cost_per_base', 15, 2); // snapshot product.cost_avg saat sale
            $table->timestamp('applied_at')->nullable();
            $table->foreignId('applied_movement_id')->nullable()
                ->constrained('stock_movements')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['opname_id', 'applied_at']);
            $table->index(['sale_id']);
            $table->index(['warehouse_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_stock_movements');
    }
};
