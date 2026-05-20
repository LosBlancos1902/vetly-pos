<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('warehouse_type', ['petshop', 'klinik', 'apotek_klinik', 'gudang'])
                ->default('petshop');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->text('address')->nullable();
            $table->timestamps();

            $table->index('warehouse_type');
            $table->index(['is_active', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
