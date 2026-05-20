<?php

namespace App\Services;

use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\User;

/**
 * Decide whether a product can be sold given current stock and the
 * acting user's permissions.
 *
 * Return shape:
 *   allowed (bool), requires_confirmation (bool), available (float), message (?string)
 */
class StockGuard
{
    public const PERM_STOCK_MINUS = 'pos.sell.stock_minus';

    /**
     * @return array{allowed: bool, requires_confirmation: bool, available: float, message: ?string}
     */
    public function canSell(int $productId, int $warehouseId, float $qty, User $user): array
    {
        $inventory = Inventory::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $available = (float) ($inventory->qty ?? 0);

        if ($available >= $qty) {
            return [
                'allowed' => true,
                'requires_confirmation' => false,
                'available' => $available,
                'message' => null,
            ];
        }

        $product = Product::find($productId);
        $mayOverride = $user->can(self::PERM_STOCK_MINUS)
            || (bool) ($product->allow_stock_minus ?? false);

        if ($mayOverride) {
            return [
                'allowed' => true,
                'requires_confirmation' => true,
                'available' => $available,
                'message' => "Stok tidak cukup (tersedia {$available}). Membutuhkan konfirmasi override.",
            ];
        }

        return [
            'allowed' => false,
            'requires_confirmation' => false,
            'available' => $available,
            'message' => "Stok tidak cukup (tersedia {$available}) dan Anda tidak punya izin override.",
        ];
    }
}
