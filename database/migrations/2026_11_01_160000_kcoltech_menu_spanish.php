<?php

use App\Services\FrontPageMenu;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * KcolTech's imported website (user's request, 2026-11-01): its Spanish pages showed
 * the English menu. Spanish labels for the menu items (by their English label) and the
 * menu rebuilt in every page -- Spanish pages get these labels and the -es pages.
 * Safe to run twice; a label already set by hand is kept.
 */
return new class extends Migration
{
    private const ES = [
        'Murano New' => 'Murano Nuevo', 'Industries' => 'Industrias', 'About' => 'Nosotros',
        'Plans' => 'Planes', 'Contact' => 'Contacto', 'WebMail' => 'WebMail', 'Murano' => 'Murano',
    ];

    public function up(): void
    {
        $cid = DB::table('companies')->where('name', 'KcolTech')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        $menu = FrontPageMenu::get((int) $cid);
        if (! $menu['available'] || ! $menu['detected']) {
            return;
        }
        $items = array_map(function ($i) {
            $i['label_es'] = ($i['label_es'] ?? '') !== '' ? $i['label_es'] : (self::ES[$i['label']] ?? '');

            return $i;
        }, $menu['items']);
        FrontPageMenu::apply((int) $cid, $items);
    }

    public function down(): void
    {
    }
};
