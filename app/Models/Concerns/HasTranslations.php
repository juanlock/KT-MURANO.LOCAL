<?php

namespace App\Models\Concerns;

/**
 * Per-locale copy for admin-authored, customer-facing text.
 *
 * A model lists its translatable fields in a TRANSLATABLE const; each one is
 * backed by a `<field>_translations` json column holding {locale: text}. The
 * plain column stays the admin-facing value and the fallback for any locale
 * left blank, so nothing breaks for stores that never translate anything.
 */
trait HasTranslations
{
    /** @return array<int, string> */
    public static function translatableFields(): array
    {
        return defined(static::class.'::TRANSLATABLE') ? static::TRANSLATABLE : [];
    }

    /**
     * The stored map for one field, tolerating a value still held as a JSON
     * string (legacy rows, imports, direct SQL edits) rather than an array.
     */
    public function translations(string $field): array
    {
        $value = $this->{$field.'_translations'} ?? null;

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    /** One field in the visitor's language, falling back to the plain column. */
    public function localized(string $field, ?string $locale = null)
    {
        if (! in_array($field, static::translatableFields(), true)) {
            return $this->{$field};
        }

        $locale = $locale ?: app()->getLocale();
        $value = trim((string) ($this->translations($field)[$locale] ?? ''));

        return $value !== '' ? $value : $this->{$field};
    }

    /**
     * Clean one submitted {locale: text} map: keep only locales the storefront
     * can render, drop blanks (so those fall back), and return null when
     * nothing is left — ready to assign straight to the json column.
     */
    public static function cleanTranslations($input): ?array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            return null;
        }

        $locales = store_locales();
        $clean = [];
        foreach ($input as $locale => $text) {
            if (isset($locales[$locale]) && trim((string) $text) !== '') {
                $clean[$locale] = trim((string) $text);
            }
        }

        return $clean ?: null;
    }

    /**
     * Apply every translatable field of a validated payload onto $data, ready
     * for fill()/update(). A field the request never mentioned is removed so a
     * partial submit leaves the stored copy untouched.
     */
    public static function applyTranslations(array $data, \Illuminate\Http\Request $request): array
    {
        foreach (static::translatableFields() as $field) {
            $key = $field.'_translations';
            if (! $request->has($key)) {
                unset($data[$key]);

                continue;
            }
            $data[$key] = static::cleanTranslations($data[$key] ?? $request->input($key));
        }

        return $data;
    }
}
