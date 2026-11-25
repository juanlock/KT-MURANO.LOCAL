<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A company's official name from SUNAT by its RUC (2026-11-24): AuraSoft's cashiers sometimes type one company's name
 * over another's RUC (RUC 20600855892 = TRUCKS PARTS DIESEL MOTOR S S.A.C. carried "PARTS AND SERVICE ANADI" most of the
 * time), and the invoice was issued to the RUC. transport_passengers.sunat_name (null = not looked up yet), sunat_at,
 * filled by transport:passengers-sunat (scheduled, a few per run). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transport_passengers', 'sunat_name')) {
            Schema::table('transport_passengers', function (Blueprint $t) {
                $t->string('sunat_name', 190)->nullable()->after('name');
                $t->timestamp('sunat_at')->nullable()->after('sunat_name');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transport_passengers', 'sunat_name')) {
            Schema::table('transport_passengers', fn (Blueprint $t) => $t->dropColumn(['sunat_name', 'sunat_at']));
        }
    }
};
