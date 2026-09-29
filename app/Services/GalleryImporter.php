<?php

namespace App\Services;

use App\Models\StoreGalleryImage;
use App\Models\StoreSetting;
use App\Support\CompanyFiles;
use Illuminate\Support\Str;

/**
 * Fills a company's Store -> Gallery with the pictures its imported website
 * (Store Settings -> Front page -> imported) actually shows: <img>, srcset,
 * lightbox links (<a href="big.jpg">) and inline background images, in the
 * order they appear, front page first.
 *
 * Only real photos are taken -- icons, logos and small thumbnails are left
 * out (min. 400x250 px, name filter), duplicates are detected by content.
 * Files are COPIED into files/company_{id}/gallery/, so re-importing the
 * website (which rewrites the site folder) never breaks the gallery, and a
 * picture already imported (same source_path or same content) is skipped.
 */
class GalleryImporter
{
    private const MIN_W = 400;
    private const MIN_H = 250;
    private const EXT = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    private const SKIP_NAME = '/(logo|icon|favicon|sprite|loader|loading|spinner|arrow|pattern|placeholder|blank|pixel|avatar|flag|badge|button|btn[-_])/i';

    /** The imported website's folder (absolute) for a company, or null. */
    public static function siteDir(int $companyId): ?string
    {
        $s = StoreSetting::where('company_id', $companyId)->first();
        $path = $s?->front_page_mode === 'imported' ? $s->front_page_path : null;
        if (! $path || ! str_starts_with($path, 'files/company_'.$companyId.'/') || ! is_file(public_path($path))) {
            return null;
        }

        return realpath(dirname(public_path($path))) ?: null;
    }

    /**
     * Picture candidates found in the site: [['rel' => site-relative path,
     * 'abs' => absolute path, 'title' => alt text or file name, 'w', 'h']].
     */
    public static function candidates(int $companyId): array
    {
        $dir = self::siteDir($companyId);
        if (! $dir) {
            return [];
        }

        $pages = glob($dir.DIRECTORY_SEPARATOR.'*.html') ?: [];
        usort($pages, fn ($a, $b) => (basename($a) !== 'index.html') <=> (basename($b) !== 'index.html') ?: strcmp($a, $b));

        $found = [];
        $hashes = [];
        $titles = [];
        foreach ($pages as $page) {
            $html = self::withoutComments((string) file_get_contents($page));
            foreach (self::refs($html) as [$url, $alt]) {
                $abs = self::resolve($dir, dirname($page), $url);
                if (! $abs) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($abs, strlen($dir) + 1));
                if (isset($found[$rel]) || preg_match(self::SKIP_NAME, basename($rel))) {
                    continue;
                }
                $size = @getimagesize($abs);
                // Very wide strips are page-header backgrounds, not photos.
                if (! $size || $size[0] < self::MIN_W || $size[1] < self::MIN_H || $size[0] / $size[1] > 3.5) {
                    continue;
                }
                $hash = md5_file($abs);
                if (isset($hashes[$hash])) {
                    continue;
                }
                $hashes[$hash] = true;
                $title = self::title($alt, $rel);
                // A repeated alt text ("hero-slider" x10) says nothing -- use the file name.
                if (isset($titles[mb_strtolower($title)])) {
                    $title = self::title('', $rel);
                }
                $titles[mb_strtolower($title)] = true;
                $found[$rel] = [
                    'rel' => $rel,
                    'abs' => $abs,
                    'hash' => $hash,
                    'title' => $title,
                    'w' => $size[0],
                    'h' => $size[1],
                ];
            }
        }

        return array_values($found);
    }

    /**
     * Copies the site's pictures into the company's gallery.
     * Returns ['added' => n, 'skipped' => n, 'found' => n].
     */
    public static function import(int $companyId): array
    {
        $candidates = self::candidates($companyId);
        $existing = StoreGalleryImage::where('company_id', $companyId)->get(['image', 'source_path']);
        $knownSources = $existing->pluck('source_path')->filter()->flip();
        $knownHashes = $existing->map(fn ($g) => is_file(public_path($g->image)) ? md5_file(public_path($g->image)) : null)->filter()->flip();

        $order = (int) StoreGalleryImage::where('company_id', $companyId)->max('sort_order');
        $relDir = CompanyFiles::dir($companyId, 'gallery');
        $added = 0;
        $skipped = 0;
        foreach ($candidates as $c) {
            if (isset($knownSources[$c['rel']]) || isset($knownHashes[$c['hash']])) {
                $skipped++;

                continue;
            }
            $ext = strtolower(pathinfo($c['abs'], PATHINFO_EXTENSION));
            $name = Str::limit(Str::slug(pathinfo($c['abs'], PATHINFO_FILENAME)), 60, '').'-'.Str::lower(Str::random(6)).'.'.$ext;
            if (! @copy($c['abs'], public_path($relDir.'/'.$name))) {
                $skipped++;

                continue;
            }
            StoreGalleryImage::create([
                'company_id' => $companyId,
                'title' => $c['title'],
                'image' => $relDir.'/'.$name,
                'width' => $c['w'],
                'height' => $c['h'],
                'sort_order' => ++$order,
                'active' => true,
                'source' => 'imported',
                'source_path' => $c['rel'],
            ]);
            $added++;
        }

        return ['added' => $added, 'skipped' => $skipped, 'found' => count($candidates)];
    }

    /** [url, alt] pairs, in document order. */
    private static function refs(string $html): array
    {
        $out = [];
        $pattern = '~<img\b[^>]*>|<source\b[^>]*>|<a\b[^>]*\bhref\s*=\s*["\'][^"\']+\.(?:jpe?g|png|webp|gif)(?:\?[^"\']*)?["\'][^>]*>|\bstyle\s*=\s*"[^"]*url\([^)]+\)[^"]*"|\bdata-(?:bg|background|image|src|lazy)\s*=\s*["\'][^"\']+["\']~i';
        preg_match_all($pattern, $html, $m);
        foreach ($m[0] as $tag) {
            $alt = preg_match('~\b(?:alt|title|data-title|data-caption)\s*=\s*(["\'])(.*?)\1~is', $tag, $a) ? html_entity_decode(trim(strip_tags($a[2]))) : '';
            if (preg_match('~\bsrcset\s*=\s*(["\'])(.*?)\1~is', $tag, $s)) {
                foreach (explode(',', $s[2]) as $part) {
                    $u = preg_split('/\s+/', trim($part))[0] ?? '';
                    if ($u !== '') {
                        $out[] = [$u, $alt];
                    }
                }
            }
            if (preg_match('~\b(?:src|href|data-src|data-bg|data-background|data-image|data-lazy)\s*=\s*(["\'])(.*?)\1~is', $tag, $s)) {
                $out[] = [trim($s[2]), $alt];
            }
            if (preg_match_all('~url\(\s*(?:&quot;|["\'])?([^"\')&]+)~i', $tag, $u)) {
                foreach ($u[1] as $x) {
                    $out[] = [trim($x), $alt];
                }
            }
        }

        return $out;
    }

    /** Absolute path of a same-site image file, or null. Never leaves the site folder. */
    private static function resolve(string $siteDir, string $pageDir, string $url): ?string
    {
        $url = html_entity_decode($url);
        if ($url === '' || preg_match('~^(data:|[a-z][a-z0-9+.-]*://|//|#|mailto:|tel:|javascript:)~i', $url)) {
            return null;
        }
        $url = rawurldecode(preg_replace('~[?#].*$~', '', $url));
        if (! in_array(strtolower(pathinfo($url, PATHINFO_EXTENSION)), self::EXT, true)) {
            return null;
        }
        // Root-relative links point at the site root, which is the copy's folder.
        $abs = realpath(str_starts_with($url, '/') ? $siteDir.$url : $pageDir.DIRECTORY_SEPARATOR.$url);
        if (! $abs || ! is_file($abs) || ! str_starts_with($abs, $siteDir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $abs;
    }

    private static function withoutComments(string $html): string
    {
        return preg_replace('/<!--(?!\s*\/?murano).*?-->/s', '', $html) ?? $html;
    }

    private static function title(string $alt, string $rel): string
    {
        $alt = trim(preg_replace('/\s+/', ' ', $alt));
        // "hero-slider", "img-video": a CSS-style slug, not a caption.
        if ($alt !== '' && mb_strlen($alt) <= 190 && ! preg_match('/^(image|img|photo|picture)?\s*\d*$/i', $alt)
            && ! preg_match('/^[a-z0-9]+([-_][a-z0-9]+)*$/', $alt)) {
            return $alt;
        }
        $file = pathinfo($rel, PATHINFO_FILENAME);
        // "1.jpg" in images/sliderImages/ -> "Slider Images 1"
        if (preg_match('/^\d+$/', $file)) {
            $folder = preg_replace('/images?$/i', '', basename(dirname($rel)));
            $file = trim(Str::snake($folder ?: 'photo', ' ').' '.$file);
        }
        $name = trim(preg_replace('/\s+/', ' ', preg_replace('/[-_]+/', ' ', $file)));

        return $name !== '' ? Str::title($name) : 'Photo';
    }
}
