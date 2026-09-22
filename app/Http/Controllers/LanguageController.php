<?php

namespace App\Http\Controllers;

use App\Models\Language;
use App\Models\Setting;
use App\Models\Translate;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LanguageController extends Controller
{
    // Column names a locale can never collide with on the wide translations
    // table (see 2026_09_21_190000_pivot_translations_to_wide_table).
    private const RESERVED_TRANSLATION_COLUMNS = ['id', 'key', 'is_default', 'customized_locales', 'created_at', 'updated_at'];

    public function load_language(Request $request)
    {
        $languages = Language::where('is_active', true)->get(['name', 'locale', 'flag']);

        return response()->json($languages);

    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        return Language::all();
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        $request->validate([
            'name' => 'required|string|max:100',
            // Locale doubles as a translations table column name (see
            // 2026_09_21_190000_pivot_translations_to_wide_table), so it must
            // be a safe SQL identifier, not just unique.
            'locale' => ['required', 'string', 'max:10', 'unique:languages', 'regex:/^[A-Za-z][A-Za-z0-9_]*$/', function ($attribute, $value, $fail) {
                if (in_array(strtolower($value), self::RESERVED_TRANSLATION_COLUMNS, true)) {
                    $fail('This locale name is reserved and cannot be used.');
                }
            }],
            // Laravel 12: image rule excludes SVG by default; use file + mimes for SVG support
            'flag' => 'nullable|file|mimes:png,jpg,jpeg,svg|max:2048',
        ]);

        if ($request->hasFile('flag')) {

            $image = $request->file('flag');
            $filename = rand(11111111, 99999999).$image->getClientOriginalName();

            $image_resize = Image::make($image->getRealPath());
            $image_resize->save(public_path('/flags/'.$filename));

        } else {
            $filename = 'no-image.png';
        }

        $language = Language::create([
            'name' => $request->name,
            'locale' => $request->locale,
            'flag' => $filename,
            'is_active' => true,
        ]);

        if (! Schema::hasColumn('translations', $request->locale)) {
            Schema::table('translations', function (Blueprint $table) use ($request) {
                $table->text($request->locale)->nullable();
            });
        }

        return $language;
    }

    public function update(Request $request, Language $language)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        $request->validate([
            'name' => 'required|string|max:100',
            'locale' => ['required', 'string', 'max:10', 'unique:languages,locale,'.$language->id, 'regex:/^[A-Za-z][A-Za-z0-9_]*$/', function ($attribute, $value, $fail) {
                if (in_array(strtolower($value), self::RESERVED_TRANSLATION_COLUMNS, true)) {
                    $fail('This locale name is reserved and cannot be used.');
                }
            }],
            // Laravel 12: image rule excludes SVG by default; use file + mimes for SVG support
            'flag' => 'nullable|file|mimes:png,jpg,jpeg,svg|max:2048',
        ]);

        // Renaming the locale carries its translations column along, so
        // nothing already translated is lost.
        $oldLocale = $language->locale;
        $newLocale = $request->locale;
        if ($oldLocale !== $newLocale) {
            if (Schema::hasColumn('translations', $oldLocale) && ! Schema::hasColumn('translations', $newLocale)) {
                Schema::table('translations', function (Blueprint $table) use ($oldLocale, $newLocale) {
                    $table->renameColumn($oldLocale, $newLocale);
                });
            } elseif (! Schema::hasColumn('translations', $newLocale)) {
                Schema::table('translations', function (Blueprint $table) use ($newLocale) {
                    $table->text($newLocale)->nullable();
                });
            }
        }

        // Handle flag upload
        if ($request->hasFile('flag')) {
            // Delete old flag if not default
            if ($language->flag && $language->flag !== 'no-image.png') {
                $oldPath = public_path('/flags/'.$language->flag);
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $image = $request->file('flag');
            $extension = strtolower($image->getClientOriginalExtension());
            $filename = rand(11111111, 99999999).'.'.$extension;
            $destination = public_path('/flags/'.$filename);

            if ($extension === 'svg') {
                // Just move the SVG without resizing
                $image->move(public_path('/flags'), $filename);
            } else {
                // Resize for raster formats
                $image_resize = \Image::make($image->getRealPath());
                $image_resize->save($destination);
            }
        } else {
            $filename = $language->flag;
        }

        // Update fields
        $language->update([
            'name' => $request->name,
            'locale' => $request->locale,
            'flag' => $filename,
        ]);

        return response()->json(['success' => true, 'language' => $language]);
    }

    public function destroy(Request $request, Language $language)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        // Drop this locale's translations column -- removes every translated
        // value for it in one shot (see
        // 2026_09_21_190000_pivot_translations_to_wide_table).
        if (Schema::hasColumn('translations', $language->locale)
            && ! in_array(strtolower($language->locale), self::RESERVED_TRANSLATION_COLUMNS, true)) {
            Schema::table('translations', function (Blueprint $table) use ($language) {
                $table->dropColumn($language->locale);
            });
        }

        // Delete the language itself
        $language->delete();

        return response()->json(['success' => true]);
    }

    public function setDefault(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        // Set all languages to not default
        Language::query()->update(['is_default' => false]);

        // Set the selected language as default
        $language = Language::findOrFail($id);
        $language->is_default = true;
        $language->save();

        // Update default_language in settings table
        if ($language->locale) {
            $setting = Setting::first();
            if ($setting) {
                $setting->update([
                    'default_language' => $language->locale,
                ]);
                // SetLocale caches this to keep it off every web request.
                Cache::forget('settings.default_language');
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Default language set successfully.',
            'default_locale' => $language->locale,
        ]);
    }

    public function setDefaultByLocale(Request $request, $locale)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        // Set selected language as default
        $language = Language::where('locale', $locale)->firstOrFail();

        // Skip if already default
        if (! $language->is_default) {
            // Set this one as default
            $language->update(['is_default' => true]);

            // Unset others
            Language::where('id', '!=', $language->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            // Update settings
            Setting::query()->update(['default_language' => $language->locale]);
            Cache::forget('settings.default_language');
        }

        return response()->json([
            'success' => true,
            'message' => 'Default language set successfully.',
            'default_locale' => $language->locale,
        ]);
    }

    public function setLocaleActive(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        // Find language by ID
        $language = Language::where('id', $id)->firstOrFail();

        // Toggle the is_active value
        $language->update([
            'is_active' => ! $language->is_active,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Updated successfully.',
            'new_status' => $language->is_active,
        ]);
    }

    // One row per key now (2026_09_21_190000_pivot_translations_to_wide_table)
    // -- no more separate "EN keys" vs "locale-only extra keys" merge, since
    // every key already has one row, with a value in its own locale column or
    // not.
    public function get_translate(Request $request, $locale)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        $perPage = (int) $request->get('per_page', 100);
        $page = (int) $request->get('page', 1);
        $search = $request->get('search');

        if (! Schema::hasColumn('translations', $locale)) {
            return response()->json([
                'data' => [],
                'total' => 0,
                'per_page' => $perPage,
                'current_page' => $page,
                'language' => $locale,
            ]);
        }

        $query = Translate::select(['id', 'key', 'is_default', 'customized_locales', DB::raw("`{$locale}` as value")])
            ->orderBy('key');

        if ($search) {
            $query->where(function ($q) use ($search, $locale) {
                $q->where('key', 'like', "%$search%")
                    ->orWhere($locale, 'like', "%$search%");
            });
        }

        $total = (clone $query)->count();
        $offset = ($page - 1) * $perPage;

        $rows = $query->skip($offset)->take($perPage)->get()->map(function ($item) use ($locale) {
            $customized = array_filter(explode(',', (string) $item->customized_locales));

            return [
                'id' => $item->id,
                'key' => $item->key,
                'value' => $item->value ?? '',
                'locale' => $locale,
                'is_customized' => in_array($locale, $customized, true),
            ];
        });

        $language = Language::where('locale', $locale)->value('name') ?? $locale;

        return response()->json([
            'data' => $rows,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'language' => $language,
        ]);
    }

    public function updateOrInsert(Request $request, $locale)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        $request->validate([
            'key' => 'required|string|max:255',
            'value' => 'nullable|string',
        ]);

        abort_unless(Schema::hasColumn('translations', $locale), 422, 'Unknown language.');

        $translation = Translate::firstOrNew(['key' => $request->key]);
        $translation->{$locale} = $request->value;

        // Flag this (key, locale) cell as client-edited so TranslationSeeder
        // never overwrites it on the next auto-update.
        $customized = array_filter(explode(',', (string) $translation->customized_locales));
        if (! in_array($locale, $customized, true)) {
            $customized[] = $locale;
        }
        $translation->customized_locales = implode(',', $customized);
        $translation->save();

        // Blade reads this table through tdb(); drop its cached map so the edit
        // shows on the login/password pages right away.
        forget_db_translations($locale);

        return response()->json([
            'message' => 'Translation saved successfully.',
            'data' => ['key' => $translation->key, 'value' => $translation->{$locale}, 'locale' => $locale],
        ]);
    }

    public function delete_translate(Request $request, $locale, $key)
    {
        $this->authorizeForUser($request->user('api'), 'translations_settings', Setting::class);

        if (! Schema::hasColumn('translations', $locale)) {
            return response()->json(['message' => 'Translation not found.'], 404);
        }

        $translation = Translate::where('key', $key)->first();

        if (! $translation) {
            return response()->json([
                'message' => 'Translation not found.',
            ], 404);
        }

        // Only the base English definition of a default/original key is
        // protected -- matches the old per-row guard, which (before the pivot
        // to one row per key) only ever applied to 'en' rows anyway.
        if ($locale === 'en' && $translation->is_default) {
            return response()->json([
                'message' => 'You cannot delete a default/original translation key.',
            ], 403);
        }

        $translation->{$locale} = null;
        $translation->save();
        forget_db_translations($locale);

        return response()->json([
            'message' => 'Translation deleted successfully.',
        ]);
    }
}
