<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Transport locations get address, phone and description (2026-11-01); Turismo
 * Cautivo's terminals filled from turismocautivo.com/agencias.html (its destinations,
 * their phones, the Lima office at Terminal Terrestre Plaza Norte). Only empty
 * values are filled. Safe to run twice.
 */
return new class extends Migration
{
    private const TERMINALS = [
        'LIMA' => ['Terminal Terrestre Plaza Norte, Oficina 108', '(01) 480-1331', '"La Ciudad de los Reyes": capital del Perú, en la costa central, a orillas del océano Pacífico.'],
        'TRUJILLO' => [null, '(01) 480-1331', 'La ciudad de la Eterna Primavera, de clima privilegiado y ambiente festivo.'],
        'CHEPEN' => [null, '(01) 480-1331', 'Ciudad de La Libertad, "La Perla del Norte": arroz, caña de azúcar y frutales.'],
        'CHICLAYO' => [null, '(074) 527110', 'Una de las urbes más importantes del Perú; ciudad comercial por excelencia.'],
    ];

    public function up(): void
    {
        Schema::table('transport_locations', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_locations', 'address')) {
                $t->string('address', 190)->nullable()->after('city');
            }
            if (! Schema::hasColumn('transport_locations', 'phone')) {
                $t->string('phone', 60)->nullable()->after('address');
            }
            if (! Schema::hasColumn('transport_locations', 'description')) {
                $t->text('description')->nullable()->after('phone');
            }
        });

        $cid = DB::table('companies')->where('name', 'Turismo Cautivo')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        foreach (self::TERMINALS as $name => [$address, $phone, $desc]) {
            $row = DB::table('transport_locations')->where('company_id', $cid)->where('name', $name)->whereNull('deleted_at')->first();
            if (! $row) {
                continue;
            }
            $set = array_filter([
                'address' => $row->address ? null : $address,
                'phone' => $row->phone ? null : $phone,
                'description' => $row->description ? null : $desc,
            ]);
            if ($set) {
                DB::table('transport_locations')->where('id', $row->id)->update($set + ['updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
    }
};
