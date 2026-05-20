<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wire users.warehouse_id → warehouses.id. Done in a separate migration
 * because `users` is created before `warehouses` (timestamp ordering).
 *
 * Cashier/staff rows MUST have warehouse_id set (fixed outlet).
 * Owner/manager rows leave it NULL (cross-warehouse access via session).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('warehouse_id')
                ->references('id')
                ->on('warehouses')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
        });
    }
};
