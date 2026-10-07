<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Online booking (2026-10-07):
 *  - settings.booking_websites: the websites that use the booking form, one URL
 *    per line (Online Booking -> Websites & codes). "Exit" / "Close" go back to
 *    the one in the code's ?return= (only if it is in this list), else the first.
 *  - booking_public_requests: every booking made on the public form, by IP
 *    (limits: 5 per 10 minutes; from the 4th in 24 h an email is required and the
 *    booking waits until the visitor clicks "I agree" in the email we send --
 *    token / payload / verified_at). See PublicBookingController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('settings', 'booking_websites')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->text('booking_websites')->nullable();
            });
        }
        if (! Schema::hasTable('booking_public_requests')) {
            Schema::create('booking_public_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->index();
                $table->string('ip', 45)->index();
                $table->string('email', 190)->nullable();
                $table->unsignedInteger('booking_id')->nullable();
                // Email check: the request waits here until "I agree" is clicked.
                $table->string('token', 64)->nullable()->unique();
                $table->json('payload')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
                $table->index(['ip', 'created_at']);
            });
        }

        $keys = [
            'Booking_Websites' => ['Websites & codes', 'Sitios web y códigos'],
            'Booking_Websites_Help' => [
                'The websites where you put the booking form, one per line (e.g. https://www.mysalon.com). "Exit" and "Close" take the customer back to the website the code is on.',
                'Los sitios web donde pone el formulario de reservas, uno por línea (ej. https://www.misalon.com). "Salir" y "Cerrar" llevan al cliente de vuelta al sitio web del código.',
            ],
            'Code_For_Website' => ['Codes for', 'Códigos para'],
            'Iframe_Code' => ['Embedded form (iframe)', 'Formulario incrustado (iframe)'],
            'Link_Url' => ['Link (URL)', 'Enlace (URL)'],
            'Button_Code' => ['Button', 'Botón'],
        ];
        foreach ($keys as $key => [$en, $es]) {
            if (! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        forget_db_translations();
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_public_requests');
        if (Schema::hasColumn('settings', 'booking_websites')) {
            Schema::table('settings', fn (Blueprint $table) => $table->dropColumn('booking_websites'));
        }
        DB::table('translations')->whereIn('key', ['Booking_Websites', 'Booking_Websites_Help', 'Code_For_Website', 'Iframe_Code', 'Link_Url', 'Button_Code'])->delete();
        forget_db_translations();
    }
};
