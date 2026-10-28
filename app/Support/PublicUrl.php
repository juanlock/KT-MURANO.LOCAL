<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Public addresses for links that leave the app (2026-10-07) -- booking codes
 * pasted on external websites, etc. Never the address the admin happens to be
 * using (kt-murano.local on a dev machine), so a code made today still works
 * after the move to production:
 *  1. the company's own public address (System Settings -> Defaults -> General
 *     -> "Public address", settings.public_base_url), else
 *  2. the server address: APP_URL in .env (System Settings -> General ->
 *     "Server address", SuperAdmin only), e.g. https://murano.kcoltech.com.
 */
class PublicUrl
{
    public static function valid(?string $url): bool
    {
        return (bool) preg_match('~^https?://[a-z0-9.-]+(:\d+)?$~i', rtrim(trim((string) $url), '/'));
    }

    public static function server(): string
    {
        return self::secure(rtrim((string) config('app.url'), '/'));
    }

    /**
     * http:// -> https:// for a real server (2026-10-27): codes pasted on https
     * websites are blocked by the browser as "mixed content" otherwise (Turismo's
     * contact form). Dev hosts (localhost, *.local, *.test, IPs) keep http.
     */
    public static function secure(string $url): string
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $dev = $host === '' || $host === 'localhost' || preg_match('/\.(local|test|localhost)$/i', $host) || filter_var($host, FILTER_VALIDATE_IP);

        return $dev ? $url : preg_replace('~^http://~i', 'https://', $url);
    }

    public static function base(int $companyId): string
    {
        $own = rtrim(trim((string) DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->value('public_base_url')), '/');

        return self::valid($own) ? $own : self::server();
    }

    /** base + path (+ query). */
    public static function to(int $companyId, string $path, array $query = []): string
    {
        return self::base($companyId).'/'.ltrim($path, '/').($query ? '?'.http_build_query($query) : '');
    }
}
