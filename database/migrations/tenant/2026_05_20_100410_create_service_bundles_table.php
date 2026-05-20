<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_bundles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')         // service product (type=service|service_with_consumption)
                ->constrained('products')
                ->cascadeOnDelete();
            $table->string('name');
            $table->decimal('service_fee', 15, 2);  // harga jual jasa (sudah include margin)
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['product_id', 'is_active']);
        });

        Schema::create('service_bundle_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('service_bundles')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('products');
            $table->decimal('qty', 15, 4);
            $table->foreignId('unit_id')->constrained('master_units');
            $table->boolean('is_optional')->default(false);
            $table->timestamps();

            $table->unique(['bundle_id', 'component_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_bundle_items');
        Schema::dropIfExists('service_bundles');
    }
};
