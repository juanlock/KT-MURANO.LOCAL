<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management -> Online Booking -> Look (per company, 2026-10-07):
 *  - booking_hide_logo: no logo on the public "Book an appointment" page
 *    (neither the header logo nor the form logo), full page and embed;
 *  - booking_bg_color: page background (#rrggbb); empty = default (light gray
 *    on the full page, transparent in the embed = the host website's own).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'booking_hide_logo')) {
                $table->boolean('booking_hide_logo')->default(false);
            }
            if (! Schema::hasColumn('settings', 'booking_bg_color')) {
                $table->string('booking_bg_color', 7)->nullable();
            }
        });

        $keys = [
            'Booking_Page_Look' => ['Look of the booking page', 'Apariencia de la página de reservas'],
            'Booking_Show_Logo' => ['Show logo', 'Mostrar logo'],
            'Booking_Show_Logo_Help' => ['Off = no logo on the booking page or the embedded form.', 'Apagado = sin logo en la página de reservas ni en el formulario incrustado.'],
            'Background_Color' => ['Background color', 'Color de fondo'],
            'Booking_Bg_Help' => ['Empty = default (light gray; transparent when embedded, so the website\'s own background shows).', 'Vacío = predeterminado (gris claro; transparente al incrustarlo, se ve el fondo del sitio web).'],
            'Default' => ['Default', 'Predeterminado'],
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
            foreach (['booking_hide_logo', 'booking_bg_color'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
        DB::table('translations')->whereIn('key', ['Booking_Page_Look', 'Booking_Show_Logo', 'Booking_Show_Logo_Help', 'Background_Color', 'Booking_Bg_Help'])->delete();
        forget_db_translations();
    }
};
