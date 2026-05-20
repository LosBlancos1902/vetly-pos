<?php

namespace App\Services;

use App\Models\Tenant\Inventory;
use App\Models\Tenant\Product;
use App\Models\User;

/**
 * Pre-flight check (advisory, not transactional) for whether a product can
 * be sold at the requested quantity. The authoritative race-safe check
 * runs inside StockMovement::record() under DB lock.
 *
 * Quantities here are converted to BASE unit before comparing to the
 * stored inventory.qty.
 *
 * Return shape:
 *   allowed (bool), requires_confirmation (bool), available (string base qty),
 *   requested_base (string base qty), message (?string)
 */
class StockGuard
{
    public const PERM_STOCK_MINUS = 'pos.sell.stock_minus';

    public function __construct(private readonly UnitConverter $units)
    {
    }

    /**
     * @return array{
     *   allowed: bool,
     *   requires_confirmation: bool,
     *   available: string,
     *   requested_base: string,
     *   message: ?string
     * }
     */
    public function canSell(int $productId, int $warehouseId, float|string $qty, ?int $unitId, User $user): array
    {
        $product = Product::with('units')->find($productId);
        if (! $product) {
            return [
                'allowed' => false,
                'requires_confirmation' => false,
                'available' => '0.0000',
                'requested_base' => '0.0000',
                'message' => "Produk #{$productId} tidak ditemukan.",
            ];
        }

        // Services don't track inventory; consumption (if any) is handled by
        // ServiceBundleService at sale time.
        if ($product->type === Product::TYPE_SERVICE
            || $product->type === Product::TYPE_SERVICE_WITH_CONSUMPTION) {
            return [
                'allowed' => true,
                'requires_confirmation' => false,
                'available' => '0.0000',
                'requested_base' => number_format((float) $qty, UnitConverter::SCALE, '.', ''),
                'message' => null,
            ];
        }

        $requestedBase = $unitId
            ? $this->units->toBase($product, $qty, $unitId)
            : number_format((float) $qty, UnitConverter::SCALE, '.', '');

        $inventory = Inventory::query()
            ->withoutGlobalScopes()
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $available = number_format((float) ($inventory->qty ?? 0), UnitConverter::SCALE, '.', '');

        if (bccomp($available, $requestedBase, UnitConverter::SCALE) >= 0) {
            return [
                'allowed' => true,
                'requires_confirmation' => false,
                'available' => $available,
                'requested_base' => $requestedBase,
                'message' => null,
            ];
        }

        $mayOverride = $user->can(self::PERM_STOCK_MINUS)
            || (bool) ($product->allow_stock_minus ?? false);

        if ($mayOverride) {
            return [
                'allowed' => true,
                'requires_confirmation' => true,
                'available' => $available,
                'requested_base' => $requestedBase,
                'message' => "Stok tidak cukup (tersedia {$available} base). Membutuhkan konfirmasi override.",
            ];
        }

        return [
            'allowed' => false,
            'requires_confirmation' => false,
            'available' => $available,
            'requested_base' => $requestedBase,
            'message' => "Stok tidak cukup (tersedia {$available} base) dan Anda tidak punya izin override.",
        ];
    }
}
