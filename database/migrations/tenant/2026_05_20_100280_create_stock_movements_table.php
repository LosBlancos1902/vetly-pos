<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
            $table->enum('type', [
                'purchase', 'sale', 'transfer_in', 'transfer_out',
                'adjustment_plus', 'adjustment_minus', 'return_in', 'return_out',
                'opname_plus', 'opname_minus',
            ]);
            $table->decimal('qty', 15, 4);                       // always in BASE unit
            $table->decimal('cost', 15, 2);
            $table->decimal('balance_qty_after', 15, 4);
            $table->decimal('balance_cost_after', 15, 2);
            $table->foreignId('unit_id_input')->nullable()
                ->constrained('master_units')->nullOnDelete();    // unit user actually typed
            $table->decimal('qty_input', 15, 4)->nullable();      // qty in that unit (display)
            $table->string('ref_type')->nullable();
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['product_id', 'warehouse_id']);
            $table->index(['ref_type', 'ref_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
