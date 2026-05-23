<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_no')->unique();
            $table->foreignId('pr_id')->nullable()->constrained('purchase_requests')->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('warehouse_id')->constrained('warehouses');

            $table->enum('payment_type', ['cash', 'tempo'])->default('cash');
            $table->unsignedInteger('payment_term_days')->default(0);

            $table->enum('status', [
                'draft', 'submitted', 'approved', 'rejected', 'cancelled', 'received',
            ])->default('draft');

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancelled_reason')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['supplier_id', 'status']);
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('po_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('unit_id')->constrained('master_units');
            $table->decimal('qty_ordered', 14, 4);
            $table->decimal('qty_received', 14, 4)->default(0);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
