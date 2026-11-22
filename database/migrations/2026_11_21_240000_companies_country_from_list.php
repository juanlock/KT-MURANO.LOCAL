<?php

use App\Support\Countries;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The company's country comes from the countries list (user, 2026-11-21). A free text typed before ("perú", an address
 * such as Ruba's "PO Box 52 Haskell, New Jersey, US - 07420", whose address is already in settings.CompanyAdress) becomes
 * the list's name, which is what DateFormats reads, so the date format stays the same. Unknown texts are left as
 * they are. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->whereNotNull('country')->where('country', '!=', '')->get(['id', 'country']) as $c) {
            $hit = Countries::match($c->country);
            if ($hit && $hit['name'] !== $c->country) {
                DB::table('companies')->where('id', $c->id)->update(['country' => $hit['name']]);
            }
        }
    }

    public function down(): void
    {
        // The earlier free texts are not kept (Ruba's address is in its settings).
    }
};
