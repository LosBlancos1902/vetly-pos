<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_payable', function (Blueprint $table) {
            $table->id();
            $table->string('ap_no')->unique();
            $table->foreignId('supplier_id')->constrained('suppliers');
            $table->foreignId('gr_id')->constrained('goods_receipts');
            $table->foreignId('po_id')->constrained('purchase_orders');
            $table->decimal('amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->date('due_date');
            $table->enum('status', ['open', 'partially_paid', 'paid', 'void'])->default('open');
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index(['supplier_id', 'status']);
            $table->index('due_date');
        });

        Schema::create('ap_payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_no')->unique();
            $table->foreignId('ap_id')->constrained('accounts_payable')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('payment_coa_code', 16); // 1101/1102/1103/1104
            $table->date('paid_at');
            $table->foreignId('paid_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->foreignId('journal_id')->nullable()->constrained('journals')->nullOnDelete();
            $table->timestamps();

            $table->index('ap_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ap_payments');
        Schema::dropIfExists('accounts_payable');
    }
};
