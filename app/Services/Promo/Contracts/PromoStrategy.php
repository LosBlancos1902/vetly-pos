<?php

namespace App\Services\Promo\Contracts;

use App\Models\Tenant\Promo;
use App\Services\Promo\PromoContext;

/**
 * Kontrak utk 5 tipe promo. Fondasi extensible: tambah tipe baru =
 * tambah kelas yg implement interface ini + register di PromoResolver.
 */
interface PromoStrategy
{
    /**
     * Apakah promo ini APPLICABLE di konteks transaksi sekarang?
     * (Validasi 5 dimensi: periode, cabang, syarat, kuota — periode +
     *  kuota sebagian sudah di-filter di resolver, strategy tinggal cek
     *  yg tipe-spesifik + halus seperti jam/hari/syarat.)
     */
    public function qualifies(Promo $promo, PromoContext $ctx): bool;

    /**
     * Hitung jumlah diskon (Rp) yg di-apply ke transaksi ini.
     * Return 0 kalau (entah kenapa) qualify tapi efektif 0.
     */
    public function computeDiscount(Promo $promo, PromoContext $ctx): float;
}
