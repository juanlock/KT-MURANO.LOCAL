<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 'complete' key (Sales/Purchases Status tag + filter dropdown) had its
 * Spanish translation as the infinitive verb "completar" ("to complete")
 * instead of the adjective "Completado" ("Completed") -- inconsistent with
 * the other status labels (Pendiente, Pedido, Cancelado), which read as
 * states, not actions.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('translations')->where('key', 'complete')->update(['es' => 'Completado']);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'complete')->update(['es' => 'completar']);
    }
};
