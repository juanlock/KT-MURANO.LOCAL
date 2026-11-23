<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Turismo Cautivo's owner / director is Julio Valladares, not "Velarde" (user, 2026-11-22: "actualizar en toda la compañía
 * y el proyecto es Julio Valladares"). The Director access login (turismo-director@kcoltech.com) was created with the
 * wrong surname (migrations 2026_11_11_150000 / 2026_11_19_120000). By email, not by id. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('email', 'like', 'turismo-director%@kcoltech.com')->where('lastname', 'Velarde')
            ->update(['lastname' => 'Valladares', 'updated_at' => now()]);
        DB::table('users')->where('email', 'like', 'turismo-manager%@kcoltech.com')->where('lastname', 'Velarde')->where('firstname', 'Julio')
            ->update(['lastname' => 'Valladares', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // The surname was wrong; nothing to put back.
    }
};
