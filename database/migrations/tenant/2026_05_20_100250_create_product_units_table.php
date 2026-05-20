<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained('master_units');
            $table->unsignedTinyInteger('level')->default(1);
            $table->decimal('conversion_to_base', 15, 4);
            $table->boolean('is_purchase_unit')->default(false);
            $table->boolean('is_sale_unit')->default(false);
            $table->decimal('price', 15, 2)->nullable();
            $table->string('barcode_per_unit')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->unique(['product_id', 'level']);
            $table->index('barcode_per_unit');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
