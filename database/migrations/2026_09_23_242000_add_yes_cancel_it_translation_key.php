<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cancel confirmation dialogs (Sales/Purchases cancelSale/cancelPurchase)
 * had BOTH buttons reading "Cancel" (okText and cancelText shared the same
 * 'Cancel' translation key) -- ambiguous in every locale, not just Spanish
 * where it's literally "Cancelar" twice. This gives the confirm button its
 * own distinct label; the dismiss button switches to the existing 'No' key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('translations')->where('key', 'Yes_Cancel_It')->exists()) {
            return;
        }
        DB::table('translations')->insert([
            'key' => 'Yes_Cancel_It',
            'is_default' => 1,
            'en' => 'Yes, Cancel It',
            'es' => 'Sí, cancelar',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'Yes_Cancel_It')->delete();
    }
};
