# Vetly POS

SaaS Point-of-Sale (sub-brand Vetly) untuk retail — petshop, klinik, F&B, minimarket.
Web responsive, touch-friendly (tablet + desktop).

## Stack

- **Laravel 11** + Inertia.js v2 + **React 18** + TypeScript
- **shadcn/ui** + Tailwind CSS (touch-optimized: tombol min 44px, primary 56px, body 16px)
- **MariaDB 10.11+** — database-per-tenant via `stancl/tenancy` v3
- **spatie/laravel-permission** v6 — roles & permissions per tenant
- **Pest** v3 — testing · Vite — assets

## Arsitektur multi-tenant

| | Domain | Database | Routes |
|---|---|---|---|
| **Central (SaaS)** | `vetly-pos.test` | `vetly_pos_central` | `routes/web.php` (landing + health) |
| **Tenant (POS app)** | `{tenant}.vetly-pos.test` | `vetly_pos_tenant_{id}` | `routes/tenant.php` (auth + seluruh app) |

Seluruh aplikasi POS (login, dashboard, kasir, master, dll) berjalan **di konteks tenant**.
Central hanya melayani landing page. `routes/web.php` `/` mendeteksi host: domain central →
Welcome, selain itu → redirect ke `/login` tenant.

## Persyaratan

- PHP 8.2+ (dites pada 8.3), Composer 2
- Node 20+, npm
- MariaDB 10.11+ berjalan di `127.0.0.1:3306`

## Setup developer baru

```bash
git clone <repo> vetly-pos && cd vetly-pos
composer install
npm install
cp .env.example .env
php artisan key:generate
```

### 1. Database & kredensial

Kredensial DB **tidak disimpan di repo**. Buat user MariaDB ber-scope (sekali saja,
butuh `sudo` / akses socket root):

```sql
-- jalankan: sudo mysql
CREATE USER IF NOT EXISTS 'vetly_pos'@'localhost' IDENTIFIED BY '<password-acak>';
CREATE DATABASE IF NOT EXISTS vetly_pos_central         CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS vetly_pos_central_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `vetly_pos_central`.*         TO 'vetly_pos'@'localhost';
GRANT ALL PRIVILEGES ON `vetly_pos_central_testing`.* TO 'vetly_pos'@'localhost';
GRANT ALL PRIVILEGES ON `vetly_pos_tenant_%`.*        TO 'vetly_pos'@'localhost';
GRANT CREATE, DROP, REFERENCES ON *.*                 TO 'vetly_pos'@'localhost';
FLUSH PRIVILEGES;
```

`vetly_pos_central_testing` adalah DB **terpisah** yang dipakai pest sebagai
central. Mutlak harus ada sebelum `./vendor/bin/pest` jalan, supaya
`RefreshDatabase` (Feature suite) tidak menyentuh `vetly_pos_central` lokal.
Detail di [Testing](#testing).

Generate password dengan `openssl rand -base64 24`, simpan di file **di luar repo**
`~/.vetly-pos-credentials` (`chmod 600`), lalu isi `DB_*` di `.env`
(`DB_PASSWORD` dibungkus tanda kutip ganda).

> Catatan: user `vetly_pos` hanya di-bind ke `localhost` (least-privilege, tanpa `@'%'`).
> Karena password base64 mengandung `+ / =`, ia **tidak bisa** dipakai via MySQL
> option-file (`~/.my.cnf`); Laravel/PDO tetap aman (password diteruskan langsung).
> Untuk cek CLI gunakan `MYSQL_PWD`.

### 2. /etc/hosts

```bash
echo -e "127.0.0.1\tvetly-pos.test\n127.0.0.1\tdemo.vetly-pos.test" | sudo tee -a /etc/hosts
```

Untuk subdomain baru, tambahkan barisnya juga (tidak ada wildcard di /etc/hosts).

### 3. Migrasi & tenant demo

```bash
php artisan migrate                 # central: tenants, domains, users, subscriptions
php artisan tenant:create demo --demo
```

`tenant:create` otomatis: buat DB tenant → migrasi 18+ tabel → seed baseline
(unit, COA, roles) → (`--demo`) seed Toko Demo + 5 produk + user owner.

### 4. Jalankan

```bash
npm run dev          # vite (terminal 1)
php artisan serve    # terminal 2
```

- Central : http://vetly-pos.test:8000
- Tenant  : http://demo.vetly-pos.test:8000
- Login   : **owner@vetly.id** / **demo123**

## Roles & permission (per tenant)

`owner` (full) · `manager` (tanpa `settings.tenant`) · `supervisor` (approval void/diskon/adjustment) ·
`cashier` (POS only) · `super_user` (override stok minus, void completed sale).

Permission penting: `pos.sell.stock_minus`, `pos.sale.void`, `pos.discount.manual`,
`inventory.adjustment`, `accounting.journal.post`.

## Services (app/Services)

`HppCalculator` (moving average) · `StockGuard` (cek jual + permission) ·
`StockMovement` (transaksi + `lockForUpdate`) · `JournalEngine` (auto-jurnal + validasi balance) ·
`ReceiptPrinter` (ESC/POS 58/80mm) · `VetlySyncService` (sync Vetly, retry) · `PromoEngine`.

Cetak struk: API mengembalikan `escpos_payload_58mm`/`_80mm` (base64) →
`resources/js/lib/bluetoothPrinter.ts` mengirim via Web Bluetooth (chunk 512B).

## Testing

```bash
./vendor/bin/pest tests/Unit          # unit (HppCalculator dll) — hijau
./vendor/bin/pest tests/Tenant        # tenant suite (POS, Inventory, Clinic) — hijau
./vendor/bin/pest                     # full suite (lihat catatan Breeze di bawah)
```

### Boundary central DB saat test

`phpunit.xml` meng-override `DB_DATABASE=vetly_pos_central_testing` (dengan
`force="true"`). Ini wajib: Pest Feature suite memakai `RefreshDatabase` yang
memanggil `migrate:fresh` di koneksi default — tanpa override, `migrate:fresh`
akan menghapus mapping tenant di `vetly_pos_central` lokal dan men-yatim-kan
DB tenant demo (`vetly_pos_tenant_demo`). Boundary ini memisahkan central
runtime dari central test sepenuhnya.

DB tenant **tidak** diisolasi — `vetly_pos_tenant_test` persist lintas pest
run dan di-re-attach oleh `TenantTestCase::provisionTestTenant()` setiap kali
suite dimulai (lihat doc-block di file). Untuk reset bersih:

```bash
mysql -u vetly_pos -p"$DB_PASSWORD" -e "DROP DATABASE vetly_pos_tenant_test;"
# pest run berikutnya akan provision ulang via Tenant::create()
```

### Catatan Breeze

> Test Feature/Auth bawaan Breeze mengasumsikan rute auth di domain central.
> Karena auth dipindah ke konteks tenant, test tersebut perlu disesuaikan
> (mock domain tenant) — belum dikerjakan.

## Catatan dev

- `CACHE_STORE=array` di lokal: `stancl/tenancy` mewajibkan cache store yang
  mendukung tagging (file/database tidak). **Produksi: gunakan Redis.**
- `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync` untuk kesederhanaan dev.
- Decimal policy: qty `DECIMAL(15,4)`, uang `DECIMAL(15,2)`, konversi `DECIMAL(15,4)`.
- Timezone `Asia/Jakarta`, locale `id`.

## Lisensi

Proprietary — Vetly.
