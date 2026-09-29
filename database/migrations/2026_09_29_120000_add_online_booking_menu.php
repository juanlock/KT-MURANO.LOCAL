<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sidebar: Booking Management -> "Online Booking" (/next/bookings/online: the public
 * booking page's link + iframe code + preview), after Trays, for every company that
 * has a saved layout (menu_positions) -- without a row a new item would land at the
 * end of the group. Companies without rows use config/menu.js order, where it already
 * follows Trays. Also the translation keys of that page / card.
 */
return new class extends Migration
{
    private string $key = '/app/bookings/online';

    public function up(): void
    {
        $now = now();
        foreach (DB::table('menu_positions')->where('parent_key', 'bookings')->distinct()->pluck('company_id') as $cid) {
            if (DB::table('menu_positions')->where('company_id', $cid)->where('item_key', $this->key)->exists()) {
                continue;
            }
            $after = DB::table('menu_positions')->where('company_id', $cid)->where('item_key', '/app/bookings/trays')->where('parent_key', 'bookings')->value('sort_order');
            $sort = $after !== null ? $after + 1
                : (int) DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'bookings')->max('sort_order') + 1;
            DB::table('menu_positions')->where('company_id', $cid)->where('parent_key', 'bookings')->where('sort_order', '>=', $sort)->increment('sort_order');
            DB::table('menu_positions')->insert([
                'company_id' => $cid, 'item_key' => $this->key, 'parent_key' => 'bookings', 'sort_order' => $sort,
                'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->translations();
    }

    private function translations(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        $keys = [
            'Online_Booking' => ['Online Booking', 'Reservas en línea', 'Réservation en ligne'],
            'Online_Booking_Page' => ['Online booking page', 'Página de reservas en línea', 'Page de réservation en ligne'],
            'Online_Booking_Page_Hint' => [
                'Customers can book an appointment themselves on this page. Every request arrives here as a Pending booking.',
                'Los clientes pueden reservar una cita ellos mismos en esta página. Cada solicitud llega aquí como una reserva Pendiente.',
                'Les clients peuvent réserver un rendez-vous eux-mêmes sur cette page. Chaque demande arrive ici comme une réservation En attente.',
            ],
            'Online_Booking_Pick_Company' => [
                'Select a company to see its public booking link and embed code.',
                'Seleccione una empresa para ver su enlace público de reservas y el código para insertar.',
                'Sélectionnez une entreprise pour voir son lien de réservation public et le code d\'intégration.',
            ],
            'Online_Booking_Module_Off' => [
                'The Bookings module is turned off for this company, so the public page is not available.',
                'El módulo de reservas está desactivado para esta empresa, así que la página pública no está disponible.',
                'Le module Réservations est désactivé pour cette entreprise, la page publique n\'est donc pas disponible.',
            ],
            'Public_Link' => ['Public link', 'Enlace público', 'Lien public'],
            'Embed_Code' => ['Embed code (iframe)', 'Código para insertar (iframe)', 'Code d\'intégration (iframe)'],
            'Embed_Code_Hint' => [
                'Paste this HTML into any external website where customers should be able to book. The frame resizes itself to its content.',
                'Pegue este HTML en cualquier sitio web externo donde los clientes deban poder reservar. El marco se ajusta solo a su contenido.',
                'Collez ce HTML sur tout site externe où les clients doivent pouvoir réserver. Le cadre s\'adapte seul à son contenu.',
            ],
            'Copy_Code' => ['Copy code', 'Copiar código', 'Copier le code'],
            'Open_Public_Page' => ['Open public page', 'Abrir página pública', 'Ouvrir la page publique'],
        ];
        $locales = array_diff(Schema::getColumnListing('translations'), ['id', 'key', 'is_default', 'customized_locales', 'created_at', 'updated_at']);
        foreach ($keys as $key => [$en, $es, $fr]) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            $row = ['key' => $key, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()];
            foreach ($locales as $l) {
                $row[$l] = $l === 'es' ? $es : ($l === 'fr' ? $fr : $en);
            }
            DB::table('translations')->insert($row);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        DB::table('menu_positions')->where('item_key', $this->key)->delete();
    }
};
