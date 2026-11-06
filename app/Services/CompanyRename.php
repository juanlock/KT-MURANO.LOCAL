<?php

namespace App\Services;

use App\Support\CompanyLogins;
use App\Support\WebsitePaths;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Rename a company in one step (user's request, 2026-11-02): Companies -> Rename.
 * Always: companies.name and settings.CompanyName (invoices, documents, emails).
 * Optional, each one a choice in the window:
 *  - website: website name (website_settings.site_name) and online store name;
 *  - helpdesk: the helpdesk sender name (helpdesk_settings.from_name);
 *  - path: the website address (website_settings.url_path) -- old links stop working;
 *  - logins: the standard logins {slug}-admin / -manager / -helpdesk / -info / -website and
 *    the demo people's (-customer, -employee... 2026-11-03) @kcoltech.com get the
 *    new prefix (email, username, last name) -- the people who use them sign in
 *    with the new email from then on; passwords do not change.
 * One transaction: all of it or nothing (global processes rule, 2026-10-07).
 */
class CompanyRename
{
    /** What would change: current and new values of every place, and the defaults of the choices. */
    public static function preview(int $companyId, string $newName): array
    {
        $old = (string) DB::table('companies')->where('id', $companyId)->value('name');
        $newName = trim($newName);
        $site = DB::table('website_settings')->where('company_id', $companyId)->first(['site_name', 'url_path']);
        $store = Schema::hasColumn('store_settings', 'store_name') ? DB::table('store_settings')->where('company_id', $companyId)->value('store_name') : null;
        $help = DB::table('helpdesk_settings')->where('company_id', $companyId)->value('from_name');
        $oldSlug = CompanyLogins::slug($companyId);
        $newSlug = Str::slug($newName, '') ?: $oldSlug;
        $logins = self::standardLogins($companyId, $oldSlug)->map(fn ($u) => [
            'id' => (int) $u->id, 'from' => $u->email, 'to' => $newSlug.Str::after($u->email, $oldSlug),
        ])->values()->all();
        $path = $site->url_path ?? null;

        return [
            'old_name' => $old,
            'new_name' => $newName,
            'website' => ['from' => $site->site_name ?? null, 'store_from' => $store, 'to' => $newName,
                'default' => ! $site || ! $site->site_name || $site->site_name === $old],
            'helpdesk' => ['from' => $help, 'to' => $newName, 'default' => ! $help || $help === $old],
            'path' => ['from' => $path, 'to' => $newName !== '' ? WebsitePaths::suggest($companyId, $newName) : $path, 'default' => false],
            'logins' => ['from_slug' => $oldSlug, 'to_slug' => $newSlug, 'list' => $logins,
                'taken' => self::takenEmails($logins), 'default' => false],
        ];
    }

    /**
     * @param  array{website?: bool, helpdesk?: bool, path?: bool|string, logins?: bool}  $opts
     * @return array what was changed
     */
    public static function apply(int $companyId, string $newName, array $opts): array
    {
        $newName = trim(strip_tags($newName));
        if ($newName === '' || mb_strlen($newName) > 190) {
            throw ValidationException::withMessages(['name' => 'Write the new name (190 characters at most).']);
        }
        if (DB::table('companies')->whereNull('deleted_at')->where('id', '!=', $companyId)->whereRaw('LOWER(name) = ?', [mb_strtolower($newName)])->exists()) {
            throw ValidationException::withMessages(['name' => 'Another company already has that name.']);
        }
        $p = self::preview($companyId, $newName);
        $path = null;
        if (! empty($opts['path'])) {
            $path = is_string($opts['path']) && $opts['path'] !== '' ? Str::lower(trim($opts['path'])) : $p['path']['to'];
            if ($problem = WebsitePaths::problem($path, $companyId)) {
                throw ValidationException::withMessages(['path' => $problem]);
            }
        }
        if (! empty($opts['logins']) && $p['logins']['taken']) {
            throw ValidationException::withMessages(['logins' => 'These emails are already used by other logins: '.implode(', ', $p['logins']['taken']).'.']);
        }

        $done = [];
        DB::transaction(function () use ($companyId, $newName, $opts, $p, $path, &$done) {
            $now = now();
            DB::table('companies')->where('id', $companyId)->update(['name' => $newName, 'updated_at' => $now]);
            DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->update(['CompanyName' => $newName, 'updated_at' => $now]);
            $done[] = 'name';

            if (! empty($opts['website'])) {
                DB::table('website_settings')->where('company_id', $companyId)->update(['site_name' => $newName, 'updated_at' => $now]);
                if (Schema::hasColumn('store_settings', 'store_name')) {
                    DB::table('store_settings')->where('company_id', $companyId)->update(['store_name' => $newName, 'updated_at' => $now]);
                }
                $done[] = 'website';
            }
            if (! empty($opts['helpdesk'])) {
                DB::table('helpdesk_settings')->where('company_id', $companyId)->update(['from_name' => $newName, 'updated_at' => $now]);
                $done[] = 'helpdesk';
            }
            if ($path !== null && $path !== $p['path']['from']) {
                DB::table('website_settings')->where('company_id', $companyId)->update(['url_path' => $path, 'updated_at' => $now]);
                $done[] = 'path';
            }
            if (! empty($opts['logins']) && $p['logins']['from_slug'] !== $p['logins']['to_slug']) {
                foreach (self::standardLogins($companyId, $p['logins']['from_slug']) as $u) {
                    // The old prefix anywhere in the username (an older admin is "adminarce", not "arce-admin").
                    $username = str_ireplace($p['logins']['from_slug'], $p['logins']['to_slug'], (string) $u->username);
                    if (DB::table('users')->where('username', $username)->where('id', '!=', $u->id)->exists()) {
                        $username = $u->username;
                    }
                    $email = $p['logins']['to_slug'].Str::after($u->email, $p['logins']['from_slug']);
                    // Last name = the company: its old full name, or its old short name ("Arce" for "Centro de Especialista Arce").
                    $isCompany = in_array(Str::lower(trim((string) $u->lastname)), [Str::lower($p['old_name']), Str::lower($p['logins']['from_slug'])], true);
                    DB::table('users')->where('id', $u->id)->update([
                        'email' => $email,
                        'username' => $username,
                        'lastname' => $isCompany ? $newName : $u->lastname,
                        'updated_at' => $now,
                    ]);
                    // The demo people's records carry the same email (CompanyDefaultPeople).
                    foreach (['clients', 'providers', 'sales_agents', 'employees'] as $table) {
                        DB::table($table)->where('user_id', $u->id)->where('email', $u->email)->update(['email' => $email, 'updated_at' => $now]);
                    }
                }
                $done[] = 'logins';
            }
        });

        if (in_array('path', $done, true)) {
            WebsitePaths::forget();
            if (app()->routesAreCached()) {
                Artisan::call('route:clear');
            }
        }

        return $done;
    }

    /** The company's standard access logins ({slug}-admin / -helpdesk / -info @kcoltech.com). */
    private static function standardLogins(int $companyId, string $slug)
    {
        return DB::table('users')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereIn('email', array_map(fn ($a) => $slug.'-'.$a.'@kcoltech.com',
                array_merge(array_keys(CompanyLogins::ACCESS), array_keys(\App\Support\CompanyDefaultPeople::PEOPLE))))
            ->orderBy('email')->get(['id', 'email', 'username', 'lastname']);
    }

    /** New emails already used by another login. */
    private static function takenEmails(array $logins): array
    {
        $to = array_column($logins, 'to', 'id');

        return DB::table('users')->whereIn('email', array_values($to))->get(['id', 'email'])
            ->filter(fn ($u) => ! isset($to[$u->id]) || $to[$u->id] !== $u->email)->pluck('email')->values()->all();
    }
}
