<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tenant\BrandingSettings;
use App\Models\Tenant\Warehouse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Branding Struk — HYBRID 2-tier.
 *
 *   - Tenant-level (singleton): brand_name, logo, footer, NPWP, license_no
 *   - Per-warehouse: address, phone, footer_override
 *
 * Gated via `settings.tenant` permission (owner-only di seeder default).
 * Logo disimpan sbg base64 data URI di DB — thermal logo umumnya kecil,
 * tidak butuh storage:link / per-tenant filesystem path.
 *
 * Edit di sini HANYA mengubah TAMPILAN struk ke depan — data transaksi
 * (sale.*) tdk disentuh. Struk transaksi lama akan render dgn branding
 * terbaru saat dibuka (keputusan: simplicity > historical accuracy;
 * tdk ada snapshot per transaksi).
 */
class BrandingController extends Controller
{
    /** Max 200 KB raw bytes utk logo (base64-decoded). Thermal logo cukup ~50KB. */
    private const LOGO_MAX_BYTES = 200 * 1024;

    private const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml'];

    public function index(): Response
    {
        $this->authorize('settings.tenant');

        $branding = BrandingSettings::singleton();
        $warehouses = Warehouse::orderByDesc('is_default')->orderBy('name')->get([
            'id', 'code', 'name', 'warehouse_type', 'address', 'phone',
            'footer_override', 'is_active', 'is_default',
        ]);

        return Inertia::render('Settings/Branding', [
            'branding' => [
                'brand_name' => $branding->brand_name,
                'logo_data' => $branding->logo_data,
                'logo_mime' => $branding->logo_mime,
                'footer_text' => $branding->footer_text,
                'npwp' => $branding->npwp,
                'license_no' => $branding->license_no,
            ],
            'warehouses' => $warehouses,
            'tenantName' => tenant() ? (string) tenant('id') : 'VETLY POS',
            'logoMaxKb' => (int) (self::LOGO_MAX_BYTES / 1024),
        ]);
    }

    /**
     * Update branding tenant (singleton). Logo upload optional.
     */
    public function updateTenant(Request $request): RedirectResponse
    {
        $this->authorize('settings.tenant');

        $data = $request->validate([
            'brand_name' => ['nullable', 'string', 'max:120'],
            'footer_text' => ['nullable', 'string', 'max:500'],
            'npwp' => ['nullable', 'string', 'max:50'],
            'license_no' => ['nullable', 'string', 'max:100'],
            'logo' => [
                'nullable', 'file',
                'mimetypes:image/png,image/jpeg,image/gif,image/svg+xml',
                'max:' . (self::LOGO_MAX_BYTES / 1024), // KB
            ],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

        // Normalize "" → null supaya field optional yg dikosongkan oleh user
        // benar-benar clear (FE kirim "" untuk empty input).
        $nn = fn (?string $s) => $s !== null && trim($s) !== '' ? $s : null;

        $branding = BrandingSettings::singleton();
        $branding->fill([
            'brand_name' => $nn($data['brand_name'] ?? null),
            'footer_text' => $nn($data['footer_text'] ?? null),
            'npwp' => $nn($data['npwp'] ?? null),
            'license_no' => $nn($data['license_no'] ?? null),
        ]);

        if ($request->boolean('remove_logo')) {
            $branding->logo_data = null;
            $branding->logo_mime = null;
        } elseif ($request->hasFile('logo')) {
            $file = $request->file('logo');
            $mime = $file->getMimeType();
            if (! in_array($mime, self::ALLOWED_MIMES, true)) {
                abort(422, 'Format logo tidak didukung. Pakai PNG/JPG/GIF/SVG.');
            }
            $bytes = file_get_contents($file->getRealPath());
            if ($bytes === false || strlen($bytes) > self::LOGO_MAX_BYTES) {
                abort(422, 'Ukuran logo melebihi batas.');
            }
            $branding->logo_data = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            $branding->logo_mime = $mime;
        }

        $branding->save();

        return back()->with('success', 'Branding tenant diperbarui.');
    }

    /**
     * Update branding per-cabang (address, phone, footer_override).
     * Field ini cuma sub-set warehouse — tdk ke-mix dgn validateWarehouse()
     * di WarehouseController supaya owner branding tdk perlu permission
     * master.manage.
     */
    public function updateWarehouse(Request $request, Warehouse $warehouse): RedirectResponse
    {
        $this->authorize('settings.tenant');

        $data = $request->validate([
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:30'],
            'footer_override' => ['nullable', 'string', 'max:500'],
        ]);

        $nn = fn (?string $s) => $s !== null && trim($s) !== '' ? $s : null;
        $warehouse->update([
            'address' => $nn($data['address'] ?? null),
            'phone' => $nn($data['phone'] ?? null),
            'footer_override' => $nn($data['footer_override'] ?? null),
        ]);

        return back()->with('success', "Branding cabang '{$warehouse->name}' diperbarui.");
    }
}
