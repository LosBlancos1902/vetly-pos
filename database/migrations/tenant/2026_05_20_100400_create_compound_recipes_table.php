<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compound_recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')         // produk hasil racikan (type=compoundable_drug)
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('name');
            $table->decimal('yield_qty', 15, 4);    // berapa unit hasil per 1 batch
            $table->foreignId('yield_unit_id')->constrained('master_units');
            $table->decimal('racik_fee', 15, 2)->default(0);
            $table->decimal('markup_percent', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        Schema::create('compound_recipe_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained('compound_recipes')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products');
            $table->decimal('qty', 15, 4);
            $table->foreignId('unit_id')->constrained('master_units');
            $table->timestamps();

            $table->unique(['recipe_id', 'component_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compound_recipe_components');
        Schema::dropIfExists('compound_recipes');
    }
};
