<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('barcode')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('base_unit_id')->constrained('master_units');
            $table->decimal('cost_avg', 15, 2)->default(0);
            $table->decimal('price', 15, 2);
            $table->decimal('min_stock', 15, 4)->default(0);
            $table->decimal('max_stock', 15, 4)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('has_expiry')->default(false);
            $table->boolean('has_batch')->default(false);
            $table->boolean('allow_stock_minus')->default(false);
            $table->timestamps();

            $table->index('barcode');
            $table->index('name');
            $table->fullText('name', 'products_name_fulltext');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
