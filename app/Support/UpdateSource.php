<?php

namespace App\Support;

/**
 * Where Murano looks for a new version (Settings -> Update, global).
 *
 * Murano is KcolTech's product: it never asks the original vendor's update
 * server any more (user's rule, 2026-11-10) -- an update from there would
 * overwrite KcolTech's code. Releases go out with the deploy protocol; the
 * in-app updater only works when `.env` `MURANO_UPDATE_URL` points to a
 * KcolTech version file (JSON with version / download_url / changelog), and it
 * only downloads from that same host.
 */
class UpdateSource
{
    /** The version file's address, or null when no update source is set. */
    public static function url(): ?string
    {
        $url = trim((string) config('murano.update_url', ''));

        return $url !== '' && preg_match('#^https://#i', $url) ? $url : null;
    }

    /** The host a package may be downloaded from (the version file's own). */
    public static function allowedHosts(): array
    {
        $host = self::url() ? parse_url(self::url(), PHP_URL_HOST) : null;

        return $host ? [$host] : [];
    }

    /** The decoded version file, or null (no source set, unreachable or not JSON). */
    public static function latest(int $timeout = 10): ?array
    {
        if (! $url = self::url()) {
            return null;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => $timeout, 'header' => "User-Agent: Murano-Updater\r\n"],
            'https' => ['timeout' => $timeout, 'header' => "User-Agent: Murano-Updater\r\n"],
        ]);
        $json = @file_get_contents($url, false, $ctx);
        $data = $json !== false ? json_decode($json, true) : null;

        return is_array($data) ? $data : null;
    }
}
