<?php

namespace App\Support;

/**
 * Ready-made websites (2026-10-20): KcolTech's website designs as static
 * copies in public/website-templates/{key}/ -- install-wide (SuperAdmin's
 * global library, not a company's files), git-tracked so they reach every PC
 * and production with the code. Built by `php artisan murano:website-templates`
 * from the HTML / PHP projects (rendered through a local web server).
 *
 * public/website-templates/templates.json = [{key, name, title, entry, pages,
 * files, mb, thumb, source}]. Offered in Website settings -> Front page ->
 * Import ("Start from a ready-made website": SiteImporter::fromTemplate) and
 * shown as KcolTech portfolio projects.
 */
class WebsiteTemplates
{
    public const DIR = 'website-templates';

    public const THUMB = '_thumb.jpg';

    public static function manifestPath(): string
    {
        return public_path(self::DIR.'/templates.json');
    }

    /** @return array<int, array> */
    public static function all(): array
    {
        $f = self::manifestPath();
        $list = is_file($f) ? json_decode((string) file_get_contents($f), true) : [];

        return array_values(array_filter((array) $list, fn ($t) => is_array($t) && ! empty($t['key']) && is_dir(self::absPath($t['key']))));
    }

    public static function get(string $key): ?array
    {
        if (! preg_match('/^[a-z0-9-]+$/', $key)) {
            return null;
        }
        foreach (self::all() as $t) {
            if ($t['key'] === $key) {
                return $t;
            }
        }

        return null;
    }

    public static function absPath(string $key): string
    {
        return public_path(self::DIR.'/'.$key);
    }

    /** For the editor: name, thumbnail and preview addresses. */
    public static function forList(): array
    {
        return array_map(fn ($t) => [
            'key' => $t['key'],
            'name' => $t['name'],
            'title' => $t['title'] ?? '',
            'pages' => $t['pages'] ?? 1,
            'mb' => $t['mb'] ?? null,
            'thumb' => asset(self::DIR.'/'.$t['key'].'/'.self::THUMB),
            'preview' => asset(self::DIR.'/'.$t['key'].'/'.$t['entry']),
        ], self::all());
    }
}
