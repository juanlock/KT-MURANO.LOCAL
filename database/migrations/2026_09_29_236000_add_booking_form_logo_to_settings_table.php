<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * System Settings -> Defaults -> Booking (per company): logo on the public
 * "Make an appointment" form (/book), shown at the top of the form card, in
 * the full page and in the iframe embed alike.
 *  - booking_form_logo_enabled: show it (default off = the form as before);
 *  - booking_form_logo: its own image (files/company_{id}/logos/...); empty =
 *    use the company logo;
 *  - booking_form_logo_width: display width in px.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'booking_form_logo_enabled')) {
                $table->boolean('booking_form_logo_enabled')->default(false);
            }
            if (! Schema::hasColumn('settings', 'booking_form_logo')) {
                $table->string('booking_form_logo', 255)->nullable();
            }
            if (! Schema::hasColumn('settings', 'booking_form_logo_width')) {
                $table->unsignedSmallInteger('booking_form_logo_width')->default(160);
            }
        });

        $keys = [
            'Booking_Form_Logo' => ['Logo on the booking form', 'Logo en el formulario de reservas'],
            'Booking_Form_Logo_Help' => [
                'Show a logo at the top of the online "Make an appointment" form (the public page and the embedded form on your website). Upload one for the form, or leave it empty to use the company logo.',
                'Muestra un logo arriba del formulario "Hacer una cita" en línea (la página pública y el formulario incrustado en su sitio web). Suba uno para el formulario o déjelo vacío para usar el logo de la empresa.',
            ],
            'Logo_Width_Px' => ['Logo width (px)', 'Ancho del logo (px)'],
            'Upload_Logo' => ['Upload logo', 'Subir logo'],
            'Remove_Logo' => ['Remove logo', 'Quitar logo'],
            'Using_Company_Logo' => ['No form logo uploaded — the company logo is used.', 'No hay logo para el formulario — se usa el logo de la empresa.'],
        ];
        foreach ($keys as $key => [$en, $es]) {
            if (! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert([
                    'key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
        forget_db_translations();
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            foreach (['booking_form_logo_enabled', 'booking_form_logo', 'booking_form_logo_width'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        DB::table('translations')->whereIn('key', [
            'Booking_Form_Logo', 'Booking_Form_Logo_Help', 'Logo_Width_Px', 'Upload_Logo', 'Remove_Logo', 'Using_Company_Logo',
        ])->delete();
        forget_db_translations();
    }
};
