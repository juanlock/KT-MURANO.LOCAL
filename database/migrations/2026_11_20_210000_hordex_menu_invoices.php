<?php

use Illuminate\Database\Migrations\Migration;

/*
 * HORDEX EIRL (2026-11-20): Clientes / Proveedores / Productos / Facturas now open the B2B screens built like its earlier
 * system; the 2026_11_20_200000 menu migration (safe to run twice) applied again where the company already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        (require __DIR__.'/2026_11_20_200000_hordex_menu.php')->up();
    }

    public function down(): void {}
};
