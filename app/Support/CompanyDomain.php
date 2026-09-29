<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which company a request's host name belongs to (companies.domains:
 * comma-separated, "www." optional). Used to serve each company's own
 * website from its own domain. Cached; call forget() after editing domains.
 */
class CompanyDomain
{
    private const CACHE_KEY = 'company_domains_map';

    public static function normalize(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('#^https?://#', '', $host);
        $host = preg_replace('#[/:].*$#', '', $host); // strip path / port

        return preg_replace('/^www\./', '', $host);
    }

    /** [host => company_id] for every live company with domains. */
    public static function map(): array
    {
        return Cache::remember(self::CACHE_KEY, 600, function () {
            $map = [];
            foreach (DB::table('companies')->whereNull('deleted_at')->whereNotNull('domains')->get(['id', 'domains']) as $c) {
                foreach (explode(',', (string) $c->domains) as $d) {
                    $d = self::normalize($d);
                    if ($d !== '') {
                        $map[$d] = (int) $c->id;
                    }
                }
            }

            return $map;
        });
    }

    public static function companyIdForHost(?string $host): ?int
    {
        return $host ? (self::map()[self::normalize($host)] ?? null) : null;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
