<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Passenger tickets (user's request, 2026-11-03): the identity document's type (DNI,
 * foreigner's card, passport, PTP / CPP) and the passenger type (adult, minor under 16,
 * infant). The document number column grows to 20 (passports). Safe to run twice.
 */
return new class extends Migration
{
    private const TEXTS = [
        'Transport_Document' => ['Identity document', 'Documento de identidad'],
        'Transport_Document_Number' => ['Document number', 'Número de documento'],
        'Transport_Doc_DNI' => ['DNI', 'DNI'],
        'Transport_Doc_CE' => ["Foreigner's card", 'Carné de extranjería'],
        'Transport_Doc_Passport' => ['Passport', 'Pasaporte'],
        'Transport_Doc_PTP' => ['PTP / CPP', 'PTP / CPP'],
        'Transport_Passenger_Type' => ['Passenger', 'Tipo de pasajero'],
        'Transport_Adult' => ['Adult', 'Adulto'],
        'Transport_Minor' => ['Minor (under 16)', 'Menor de edad (menor de 16)'],
        'Transport_Infant' => ['Infant', 'Infante'],
    ];

    public function up(): void
    {
        Schema::table('transport_tickets', function (Blueprint $t) {
            if (! Schema::hasColumn('transport_tickets', 'doc_type')) {
                $t->string('doc_type', 12)->default('dni')->after('warehouse_id'); // dni | ce | passport | ptp
            }
            if (! Schema::hasColumn('transport_tickets', 'passenger_type')) {
                $t->string('passenger_type', 10)->default('adult')->after('luggage'); // adult | minor | infant
            }
        });
        Schema::table('transport_tickets', function (Blueprint $t) {
            $t->string('dni', 20)->change(); // the document number
        });

        foreach (self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                ->where(fn ($q) => $q->whereNull('en')->orWhere('en', $key))->update(['en' => $en]);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
    }
};
