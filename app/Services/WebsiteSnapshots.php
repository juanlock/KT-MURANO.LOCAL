<?php

namespace App\Services;

use App\Models\StorePage;
use App\Models\StoreSetting;
use App\Support\CompanyFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Saved websites (2026-10-06): an import replaces the imported website, so the
 * current one is copied first to files/company_{id}/website/snapshots/{stamp}/
 * and listed in Website settings -> Front page as a theme to switch back to.
 */
class WebsiteSnapshots
{
    /** The folder of the imported website served now (null = none). */
    private static function currentRoot(int $companyId, ?string $entry): ?string
    {
        $entry = (string) $entry;
        if ($entry === '' || ! str_starts_with($entry, 'files/company_'.$companyId.'/') || str_contains($entry, '..') || ! is_file(public_path($entry))) {
            return null;
        }
        if (preg_match('#^(files/company_'.$companyId.'/(?:store/sites|website/snapshots)/[^/]+)/#', $entry, $m)) {
            return $m[1];
        }

        return dirname($entry);
    }

    /** Copy the imported website served now (before it is replaced). Null when there is none, or it is already a saved copy. */
    public static function take(int $companyId): ?object
    {
        $s = StoreSetting::where('company_id', $companyId)->first();
        $root = $s ? self::currentRoot($companyId, $s->front_page_path) : null;
        if (! $root || str_contains($root, '/website/snapshots/')) {
            return null;
        }
        // Already saved (same site, same import)?
        $saved = DB::table('website_snapshots')->where('company_id', $companyId)
            ->where('source_url', $s->front_page_url)->where('imported_at', $s->front_page_imported_at)->first();
        if ($saved && is_file(public_path($saved->entry))) {
            return $saved;
        }
        $stamp = now()->format('Y-m-d_His');
        $dest = CompanyFiles::dir($companyId, 'website/snapshots/'.$stamp);
        File::copyDirectory(public_path($root), public_path($dest));

        $host = parse_url((string) $s->front_page_url, PHP_URL_HOST) ?: basename($root);
        $id = DB::table('website_snapshots')->insertGetId([
            'company_id' => $companyId,
            'name' => preg_replace('/^www\./', '', $host).' — '.($s->front_page_imported_at ? \Illuminate\Support\Carbon::parse($s->front_page_imported_at)->format('Y-m-d H:i') : now()->format('Y-m-d H:i')),
            'source_url' => $s->front_page_url,
            'path' => $dest,
            'entry' => $dest.'/'.ltrim(substr($s->front_page_path, strlen($root)), '/'),
            'imported_at' => $s->front_page_imported_at,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('website_snapshots')->find($id);
    }

    public static function list(int $companyId): array
    {
        $current = (string) StoreSetting::where('company_id', $companyId)->value('front_page_path');

        return DB::table('website_snapshots')->where('company_id', $companyId)->orderByDesc('id')->get()
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->name, 'source_url' => $r->source_url, 'imported_at' => $r->imported_at,
                'saved_at' => $r->created_at, 'preview' => '/'.$r->entry, 'in_use' => $r->entry === $current,
                'available' => is_file(public_path($r->entry)),
            ])->all();
    }

    /** Make a saved website the front page again (the one served now is saved first). */
    public static function use(int $companyId, int $id): void
    {
        $r = DB::table('website_snapshots')->where('company_id', $companyId)->where('id', $id)->first();
        abort_unless($r && is_file(public_path($r->entry)), 404);
        self::take($companyId);
        FrontPage::setImported($companyId, $r->source_url, $r->entry);
        StorePage::where('company_id', $companyId)->where('slug', 'home')->update(['site_path' => $r->entry, 'use_site_design' => true]);
    }

    public static function delete(int $companyId, int $id): void
    {
        $r = DB::table('website_snapshots')->where('company_id', $companyId)->where('id', $id)->first();
        abort_unless($r, 404);
        abort_if($r->entry === (string) StoreSetting::where('company_id', $companyId)->value('front_page_path'), 422, 'This saved website is the one in use.');
        if (str_starts_with($r->path, 'files/company_'.$companyId.'/website/snapshots/') && ! str_contains($r->path, '..')) {
            File::deleteDirectory(public_path($r->path));
        }
        DB::table('website_snapshots')->where('id', $r->id)->delete();
    }
}
