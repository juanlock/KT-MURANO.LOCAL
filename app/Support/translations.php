<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Blade access to the `translations` DATABASE table.
 *
 * The app has two disjoint translation stores:
 *
 *   - the `translations` table — what Settings → Translations edits, and what
 *     the Vue admin/POS reads through GET /api/translations/{locale};
 *   - resources/lang/{locale}/*.php files — what Laravel's own __() reads.
 *
 * Guest-facing Blade pages (login, password reset, 2FA) used to hardcode
 * English because __() could only reach the files, which carry no keys for
 * them and are not editable from the UI. tdb() closes that gap: Blade now
 * reads the same rows the admin edits, so a string changed in the translation
 * manager shows up on the login page too.
 */
if (! function_exists('db_translations')) {
    /** Flat key => value map for one locale. Cached; memoized per request. */
    function db_translations(?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();

        static $memo = [];
        if (isset($memo[$locale])) {
            return $memo[$locale];
        }

        $map = Cache::remember('db_translations.'.$locale, 600, function () use ($locale) {
            try {
                if (! Schema::hasTable('translations')) {
                    return [];
                }

                return DB::table('translations')->where('locale', $locale)
                    ->pluck('value', 'key')->all();
            } catch (\Throwable $e) {
                // No DB yet (installer / update flow) — fall back to literals.
                return [];
            }
        });

        return $memo[$locale] = $map;
    }
}

if (! function_exists('db_translations_index')) {
    /**
     * Lowercased index for one locale. The legacy key set mixes casings
     * ('warehouse' vs 'Warehouse'); same rescue the Vue side does in
     * resources/src/i18n/index.js so a casing miss doesn't leak a raw key.
     */
    function db_translations_index(?string $locale = null): array
    {
        $locale = $locale ?: app()->getLocale();

        static $memo = [];
        if (isset($memo[$locale])) {
            return $memo[$locale];
        }

        $idx = [];
        foreach (db_translations($locale) as $k => $v) {
            $idx[mb_strtolower($k)] = $v;
        }

        return $memo[$locale] = $idx;
    }
}

if (! function_exists('forget_db_translations')) {
    /** Drop the cached map(s) — call after writing to the translations table. */
    function forget_db_translations(?string $locale = null): void
    {
        $locales = $locale ? [$locale] : array_keys(\App\Http\Middleware\SetLocale::SUPPORTED);

        foreach ($locales as $loc) {
            Cache::forget('db_translations.'.$loc);
        }
    }
}

if (! function_exists('tdb')) {
    /**
     * Translate KEY from the translations table, falling back (in order) to the
     * same key in another casing, the English row, and finally $fallback — the
     * English literal the Blade view used to hardcode, so an untranslated page
     * reads exactly as it did before.
     *
     * @param  array<string, string>  $replace  :placeholder => value
     */
    function tdb(string $key, ?string $fallback = null, array $replace = []): string
    {
        $locale = app()->getLocale();
        $lower = mb_strtolower($key);

        $value = null;
        foreach (array_unique([$locale, 'en']) as $loc) {
            $map = db_translations($loc);
            // An empty row is a missing translation, not a blank label.
            if (isset($map[$key]) && $map[$key] !== '') {
                $value = $map[$key];
                break;
            }
            $idx = db_translations_index($loc);
            if (isset($idx[$lower]) && $idx[$lower] !== '') {
                $value = $idx[$lower];
                break;
            }
        }

        $value ??= ($fallback ?? $key);

        foreach ($replace as $search => $replacement) {
            $value = str_replace(':'.$search, (string) $replacement, $value);
        }

        return $value;
    }
}

if (! function_exists('login_text')) {
    /**
     * One of the admin-overridable login strings, translated.
     *
     * These four columns shipped with English baked in as a COLUMN DEFAULT
     * (2025_12_07_000000_add_login_appearance_to_settings_table, since
     * corrected), so on every install predating that fix the settings row
     * already "has a value" and the override permanently shadows the
     * translation — the login page stays English whatever the company language.
     *
     * A stored value byte-identical to one of those defaults was therefore
     * never authored by anyone; it is the default. Treat it, and a blank, as
     * "not set" and fall through to the translated string. Text an admin
     * actually typed is left exactly as typed.
     */
    function login_text(?string $stored, string $key, string $fallback): string
    {
        $stored = trim((string) $stored);

        $legacyDefaults = [
            'Welcome back!',
            'Sign in to access your account and keep your operations in sync.',
            'Sign In',
            'Access your dashboard and manage everything from one place.',
        ];

        if ($stored === '' || in_array($stored, $legacyDefaults, true)) {
            return tdb($key, $fallback);
        }

        return $stored;
    }
}

if (! function_exists('locale_is_rtl')) {
    /**
     * Mirrors RTL_LOCALES in resources/src/i18n/index.js — Arabic is the only
     * RTL locale in SetLocale::SUPPORTED. Extend both together.
     */
    function locale_is_rtl(?string $locale = null): bool
    {
        return in_array($locale ?: app()->getLocale(), ['ar'], true);
    }
}

if (! function_exists('locale_dir')) {
    /** 'rtl' or 'ltr', for the <html dir> attribute. */
    function locale_dir(?string $locale = null): string
    {
        return locale_is_rtl($locale) ? 'rtl' : 'ltr';
    }
}
