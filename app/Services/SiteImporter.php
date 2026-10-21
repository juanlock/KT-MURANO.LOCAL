<?php

namespace App\Services;

use App\Models\StorePage;
use App\Support\CompanyFiles;
use Illuminate\Support\Facades\Http;

/**
 * Imports a company's existing website as its Store -> Pages "Home" page in
 * the ORIGINAL design (see StorePage.site_path / use_site_design):
 *
 *  - fromDirectory(): a static site folder (HTML/CSS/JS). Copies the root
 *    .html pages and every local file they (and their CSS/JS) reference --
 *    never .php (it would execute under public/).
 *  - fromUrl(): a live site (PHP/CMS). Saves each same-site page's RENDERED
 *    HTML (index.php -> index.html, about.php -> about.html), downloads the
 *    assets it uses (CSS url() included) and rewrites same-site links so the
 *    copy works on its own.
 *
 * Both publish into public/files/company_{id}/website/sites/{name}/ (the
 * Website module's folder; store/sites/ before 2026-10-07) and
 * upsert the company's `home` page pointing at the copy.
 */
class SiteImporter
{
    private const SKIP_EXT = ['php', 'phtml', 'cgi', 'pl', 'py', 'sh', 'htaccess'];

    /* ---------------------------------------------------------- folder */

    public static function fromDirectory(int $companyId, string $dir, string $name, string $entry = 'index.html'): array
    {
        $dir = rtrim($dir, '\\/');
        $rel = CompanyFiles::dir($companyId, 'website/sites/'.$name);
        $abs = public_path($rel);
        $copied = [];

        // Start at the entry page and follow its links, so only pages the site
        // actually reaches are published (not old/backup copies lying around).
        $queue = [$entry];
        while ($queue) {
            $file = self::normalize(array_shift($queue));
            if ($file === '' || isset($copied[$file]) || in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::SKIP_EXT, true)) {
                continue;
            }
            $src = $dir.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $file);
            if (! is_file($src)) {
                continue;
            }
            self::put($abs.'/'.$file, file_get_contents($src));
            $copied[$file] = true;

            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, ['html', 'htm', 'css', 'js'], true)) {
                $base = dirname($file) === '.' ? '' : dirname($file).'/';
                foreach (self::localRefs(file_get_contents($src), $ext) as $ref) {
                    $queue[] = $base.$ref;
                }
            }
        }

        $page = self::upsertHome($companyId, $rel.'/'.$entry);

        return ['files' => count($copied), 'path' => $rel, 'page_id' => $page->id];
    }

    /* ------------------------------------------------------------- URL */

    public static function fromUrl(int $companyId, string $url, string $name, int $maxPages = 30): array
    {
        $rel = CompanyFiles::dir($companyId, 'website/sites/'.$name);
        $r = self::crawl($url, public_path($rel), $maxPages);
        $page = self::upsertHome($companyId, $rel.'/'.$r['entry']);

        return ['pages' => $r['pages'], 'assets' => $r['assets'], 'path' => $rel, 'page_id' => $page->id];
    }

    /* -------------------------------------------------------- template */

    /**
     * A ready-made website from the library (App\Support\WebsiteTemplates,
     * public/website-templates/{key}/, 2026-10-20) copied into a NEW folder of
     * the company's website (sites/{key}, else {key}-2, -3 ...: nothing the
     * company already has is overwritten or deleted) and set as its home page.
     */
    public static function fromTemplate(int $companyId, string $key): array
    {
        $tpl = \App\Support\WebsiteTemplates::get($key);
        if (! $tpl) {
            throw new \RuntimeException('Unknown website template: '.$key);
        }
        $name = $key;
        for ($n = 2; is_dir(public_path('files/company_'.$companyId.'/website/sites/'.$name)); $n++) {
            $name = $key.'-'.$n;
        }
        $rel = CompanyFiles::dir($companyId, 'website/sites/'.$name);
        $abs = public_path($rel);
        \Illuminate\Support\Facades\File::copyDirectory(\App\Support\WebsiteTemplates::absPath($key), $abs);
        @unlink($abs.'/'.\App\Support\WebsiteTemplates::THUMB);
        $page = self::upsertHome($companyId, $rel.'/'.$tpl['entry']);

        return ['pages' => (int) ($tpl['pages'] ?? 1), 'assets' => (int) ($tpl['files'] ?? 0), 'path' => $rel, 'entry' => $rel.'/'.$tpl['entry'], 'page_id' => $page->id];
    }

    /**
     * Crawl a live site into $abs (any folder): each same-site page's rendered
     * HTML + the assets it uses, links rewritten so the copy works on its own.
     *
     * @return array{pages: int, assets: int, entry: string}
     */
    public static function crawl(string $url, string $abs, int $maxPages = 30): array
    {
        // Follow the start URL's redirects: the real site may live in a
        // subfolder (e.g. https://www.rubagardenlandscape.com/ -> /www/).
        $first = Http::timeout(30)->withOptions(['verify' => false])->get($url);
        $effective = (string) ($first->effectiveUri() ?? $url);
        $root = preg_replace('#/[^/]*$#', '/', $effective);
        $prefix = parse_url($root, PHP_URL_PATH) ?: '/';
        $host = strtolower(parse_url($root, PHP_URL_HOST));
        $bareHost = preg_replace('/^www\./', '', $host);

        $isSameSite = fn (string $u) => ($h = parse_url($u, PHP_URL_HOST)) === null
            || preg_replace('/^www\./', '', strtolower($h)) === $bareHost;
        // A same-site path, relative to the site root ("/www/assets/x" ->
        // "assets/x" when the site lives under /www/).
        $sitePath = function (string $p) use ($prefix) {
            if (str_starts_with($p, '/')) {
                $p = str_starts_with($p, $prefix) ? substr($p, strlen($prefix)) : ltrim($p, '/');
            }

            return self::normalize($p);
        };
        // Same-site page URL -> local file name (index.php -> index.html).
        $pageFile = function (string $path) use ($sitePath) {
            $path = $sitePath(parse_url($path, PHP_URL_PATH) ?? '');
            if ($path === '' || str_ends_with($path, '/') || ! str_contains(basename($path), '.')) {
                $path = rtrim($path, '/').($path === '' ? '' : '/').'index.php';
            }

            return preg_replace('/\.(php|asp|aspx|jsp)$/i', '.html', $path);
        };

        $pages = [];
        $assets = [];
        $queue = [''];
        $seen = [];
        while ($queue && count($pages) < $maxPages) {
            $path = array_shift($queue);
            $key = $pageFile($path);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $res = Http::timeout(30)->withOptions(['verify' => false])->get($root.$sitePath($path));
            if (! $res->successful() || ! str_contains(strtolower($res->header('Content-Type')), 'html')) {
                continue;
            }
            $html = $res->body();

            // Same-site links: queue pages, collect assets.
            preg_match_all('/\b(?:href|src|data-src|data-bg|poster)\s*=\s*["\']([^"\']+)["\']/i', $html, $m);
            preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $html, $m2);
            foreach (array_merge($m[1], $m2[1]) as $ref) {
                $ref = html_entity_decode(trim($ref));
                if ($ref === '' || preg_match('/^(#|mailto:|tel:|javascript:|data:)/i', $ref) || ! $isSameSite($ref)) {
                    continue;
                }
                $p = parse_url($ref, PHP_URL_PATH) ?? '';
                $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                if ($ext === '' || in_array($ext, ['php', 'html', 'htm', 'asp', 'aspx'], true)) {
                    if (! preg_match('#(^|/)(webmail|cpanel|wp-admin|admin|login)(/|$)#i', $p)) {
                        $queue[] = $p;
                    }
                } else {
                    $assets[$sitePath($p)] = true;
                }
            }
            // Files the page's inline scripts load by name (relative to the page).
            $pageDir = dirname($key) === '.' ? '' : dirname($key).'/';
            foreach (self::localRefs($html, 'html') as $ref) {
                $rp = strtolower(pathinfo($ref, PATHINFO_EXTENSION));
                if ($rp !== '' && ! in_array($rp, ['php', 'html', 'htm', 'asp', 'aspx'], true)) {
                    $assets[self::normalize($pageDir.$ref)] = true;
                }
            }
            $pages[$key] = $html;
        }

        // Rewrite same-site links inside the saved pages.
        foreach ($pages as $file => $html) {
            $depth = substr_count($file, '/');
            $up = str_repeat('../', $depth);
            $html = preg_replace_callback('/\b(href|src|data-src|data-bg|poster)(\s*=\s*)(["\'])([^"\']+)\3/i',
                function ($mm) use ($isSameSite, $pageFile, $sitePath, $up) {
                    $ref = html_entity_decode($mm[4]);
                    if (preg_match('/^(#|mailto:|tel:|javascript:|data:)/i', $ref) || ! $isSameSite($ref)) {
                        return $mm[0];
                    }
                    $p = parse_url($ref, PHP_URL_PATH) ?? '';
                    $q = parse_url($ref, PHP_URL_FRAGMENT);
                    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
                    $local = ($ext === '' || in_array($ext, ['php', 'html', 'htm', 'asp', 'aspx'], true))
                        ? $pageFile($p) : $sitePath($p);

                    return $mm[1].$mm[2].$mm[3].$up.$local.($q ? '#'.$q : '').$mm[3];
                }, $html);
            self::put($abs.'/'.$file, $html);
        }

        // Download assets (+ what their CSS references).
        $done = [];
        $queue = array_keys($assets);
        while ($queue) {
            $a = self::normalize(array_shift($queue));
            $ext = strtolower(pathinfo($a, PATHINFO_EXTENSION));
            if ($a === '' || isset($done[$a]) || in_array($ext, self::SKIP_EXT, true)) {
                continue;
            }
            $done[$a] = true;
            $res = Http::timeout(60)->withOptions(['verify' => false])->get($root.$a);
            if (! $res->successful()) {
                continue;
            }
            $body = $res->body();
            if ($ext === 'css') {
                $base = dirname($a) === '.' ? '' : dirname($a).'/';
                foreach (self::localRefs($body, 'css') as $ref) {
                    $queue[] = $base.$ref;
                }
            }
            // Scripts that load more files ("js/superfish.js", "css/x.css"): those
            // paths are relative to the page (site root), not to the script.
            if ($ext === 'js') {
                foreach (self::localRefs($body, 'js') as $ref) {
                    $queue[] = $ref;
                }
            }
            self::put($abs.'/'.$a, $body);
        }

        $entry = isset($pages['index.html']) ? 'index.html' : (array_key_first($pages) ?? 'index.html');

        return ['pages' => count($pages), 'assets' => count($done), 'entry' => $entry];
    }

    /* --------------------------------------------------------- helpers */

    /** Relative (local) references inside HTML / CSS / JS text. */
    private static function localRefs(string $text, string $ext): array
    {
        $refs = [];
        if (in_array($ext, ['html', 'htm'], true)) {
            preg_match_all('/\b(?:href|src|data-src|data-bg|poster)\s*=\s*["\']([^"\']+)["\']/i', $text, $m);
            $refs = $m[1];
        }
        preg_match_all('/url\(\s*["\']?([^"\')]+)["\']?\s*\)/i', $text, $m2);
        $refs = array_merge($refs, $m2[1]);
        if ($ext === 'css') {
            // @import "reset.css"; (without url())
            preg_match_all('/@import\s*["\']([^"\']+)["\']/i', $text, $m4);
            $refs = array_merge($refs, $m4[1]);
        }
        if (in_array($ext, ['js', 'html', 'htm'], true)) {
            // Files loaded by scripts (also inline ones): any quoted relative path
            // ending in an asset extension ("plugins/yoxview/yoxview.css", "scripts/museconfig.js?crc=1").
            preg_match_all('/["\']((?:\.\.?\/)?[\w\-.\/]+\/[\w\-.]+\.(?:js|css|png|jpe?g|gif|svg|webp|woff2?|ttf|eot|otf|json|mp4|webm|mp3)(?:\?[^"\'\s]*)?)["\']/i', $text, $m3);
            $refs = array_merge($refs, $m3[1]);
        }

        return array_values(array_filter(array_map(function ($r) {
            $r = html_entity_decode(trim($r));
            if ($r === '' || preg_match('/^([a-z]+:|\/\/|#|\/)/i', $r)) {
                return null; // absolute / external / anchor / root-relative
            }

            return preg_replace('/[?#].*$/', '', $r);
        }, $refs)));
    }

    /** Resolve "a/b/../c" and strip "./". */
    private static function normalize(string $path): string
    {
        $out = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($out);
                continue;
            }
            $out[] = rawurldecode($seg);
        }

        return implode('/', $out);
    }

    private static function put(string $absFile, string $contents): void
    {
        // A reference to a folder ("images/icons") is not a file: never write over a folder.
        if (is_dir($absFile)) {
            return;
        }
        if (! is_dir(dirname($absFile))) {
            mkdir(dirname($absFile), 0755, true);
        }
        file_put_contents($absFile, $contents);
    }

    private static function upsertHome(int $companyId, string $sitePath): StorePage
    {
        $html = @file_get_contents(public_path($sitePath)) ?: '';
        $title = trim(html_entity_decode(strip_tags(preg_match('/<title>(.*?)<\/title>/is', $html, $t) ? $t[1] : '')), " \t\n\r\0\x0B:*-");
        $title = $title ?: (\App\Models\Company::whereKey($companyId)->value('name') ?: 'Home');

        $page = StorePage::firstOrNew(['company_id' => $companyId, 'slug' => 'home']);
        $page->fill([
            'title' => 'Home',
            'site_path' => $sitePath,
            'use_site_design' => true,
            'seo_title' => $page->seo_title ?: $title,
            'published' => true,
        ]);
        if (! $page->content) {
            // Editable fallback (shown when "original design" is switched off).
            $page->content = '<h1>'.e($title).'</h1><p><a href="/'.e($sitePath).'" target="_blank">Open the website</a></p>';
        }
        $page->save();

        return $page;
    }
}
