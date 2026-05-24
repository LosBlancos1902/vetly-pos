<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // is_active: soft-deactivate pattern (consistent dgn Categories,
            // Suppliers). CustomerPicker di POS filter aktif saja.
            $table->boolean('is_active')->default(true)->after('vetly_customer_id');
            // address + notes: field tambahan F2.
            $table->text('address')->nullable()->after('is_active');
            $table->text('notes')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'address', 'notes']);
        });
    }
};
