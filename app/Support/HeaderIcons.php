<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The header's icons (2026-10-07): which ones show.
 *
 *  - Company: System Settings -> Defaults -> Users -> "Header icons", for every
 *    user of the company. Three icons keep their older switch as the source:
 *    language = settings.show_language (System Settings), customize =
 *    settings.customize_button_visible (Appearance), pos =
 *    pos_settings.show_pos_button (POS Settings) -- the Users tab writes those;
 *    the others are a list of hidden keys in settings.header_icons.
 *  - User: Profile -> "Header icons": users.header_icons {key: true|false};
 *    a key that isn't there follows the company.
 *  The SPA shows an icon when the user's choice (else the company's) allows
 *  it, on top of the icon's own permission (POS, clock in / out).
 */
class HeaderIcons
{
    public const KEYS = ['pos', 'customize', 'install', 'summary', 'fullscreen', 'manual', 'language', 'notifications', 'clock', 'logout'];

    /** Keys whose company switch lives in an older column (not in settings.header_icons). */
    private const LEGACY = ['pos', 'customize', 'language'];

    /** Hidden keys for the whole company. */
    public static function companyHidden(int $companyId): array
    {
        $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')
            ->first(['header_icons', 'show_language', 'customize_button_visible']);
        $hidden = array_values(array_intersect(json_decode((string) ($s->header_icons ?? '[]'), true) ?: [], self::KEYS));
        if ($s && ! $s->show_language) {
            $hidden[] = 'language';
        }
        if ($s && isset($s->customize_button_visible) && ! $s->customize_button_visible) {
            $hidden[] = 'customize';
        }
        $pos = DB::table('pos_settings')->where('company_id', $companyId)->value('show_pos_button');
        if ($pos !== null && ! (int) $pos) {
            $hidden[] = 'pos';
        }

        return array_values(array_unique($hidden));
    }

    /**
     * Saves the company's hidden list (Defaults -> Users). Language is saved by
     * the same form's show_language field, so it is left alone here.
     */
    public static function saveCompanyHidden(int $companyId, array $hidden): void
    {
        $hidden = array_values(array_unique(array_intersect($hidden, self::KEYS)));
        DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->update([
            'header_icons' => json_encode(array_values(array_diff($hidden, self::LEGACY))),
            'customize_button_visible' => in_array('customize', $hidden, true) ? 0 : 1,
        ]);
        DB::table('pos_settings')->where('company_id', $companyId)->update(['show_pos_button' => in_array('pos', $hidden, true) ? 0 : 1]);
    }

    /** The user's own choices {key: bool} (only valid keys). */
    public static function userChoices(?string $json): array
    {
        $raw = json_decode((string) $json, true);

        return is_array($raw) ? array_map('boolval', array_intersect_key($raw, array_flip(self::KEYS))) : [];
    }
}
