<?php

namespace App\Services;

use App\Models\StoreSetting;
use App\Support\CompanyFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The company Portfolio shown as a front-page section (since 2026-10-06 the
 * data is the Portfolio MODULE's: categories + published works -- the old
 * store_portfolio_* tables were merged into it) --
 *  - our store front page: the "portfolio" Homepage Block (store/index);
 *  - an imported website: inserted into its front page at serve time
 *    (FrontPage::importedResponse), replacing the site's own portfolio
 *    section or placed before its footer -- always live from this data, so
 *    re-importing the website never loses it.
 *
 * import() copies an imported website's own portfolio into it. Two markups
 * are understood:
 *  - filter list + items tagged data-type / data-category (e.g. KcolTech:
 *    <li class="catWeb"><a>Web Design</a> ... <li data-type="catWeb">);
 *  - tiles with class "portfolio <group>" whose group names come from the
 *    site's menu (#hairstyle -> "Hair Style") or data-filter links (NewEra).
 */
class Portfolio
{
    private const EXT = 'jpe?g|png|webp|gif';

    /* ------------------------------------------------------ front page */

    public static function sectionSettings(int $companyId): array
    {
        $raw = StoreSetting::where('company_id', $companyId)->value('portfolio_section');
        $cfg = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;

        return [
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'placement' => in_array($cfg['placement'] ?? '', ['replace', 'before_footer', 'end'], true) ? $cfg['placement'] : 'replace',
            'title' => (string) ($cfg['title'] ?? 'Portfolio'),
            'subtitle' => (string) ($cfg['subtitle'] ?? ''),
            'limit' => max(1, min(60, (int) ($cfg['limit'] ?? 24))),
        ];
    }

    public static function saveSectionSettings(int $companyId, array $cfg): array
    {
        StoreSetting::forCompany($companyId)->forceFill(['portfolio_section' => json_encode([
            'enabled' => (bool) ($cfg['enabled'] ?? false),
            'placement' => in_array($cfg['placement'] ?? '', ['replace', 'before_footer', 'end'], true) ? $cfg['placement'] : 'replace',
            'title' => Str::limit(trim((string) ($cfg['title'] ?? '')), 120, ''),
            'subtitle' => Str::limit(trim((string) ($cfg['subtitle'] ?? '')), 200, ''),
            'limit' => max(1, min(60, (int) ($cfg['limit'] ?? 24))),
        ])])->save();

        return self::sectionSettings($companyId);
    }

    /**
     * Groups (with at least one visible picture) + visible pictures -- from the
     * Portfolio MODULE since 2026-10-06 (one Portfolio for the company): its
     * categories and its published works with a cover. Same shape as before
     * (item: image, title, subtitle, link, group_id; group: id, name).
     */
    public static function data(int $companyId, int $limit = 60): array
    {
        $items = DB::table('portfolio_projects')->where('company_id', $companyId)->where('published', true)
            ->whereNotNull('cover')->orderBy('sort_order')->orderBy('id')->limit($limit)->get()
            ->map(fn ($p) => (object) [
                'image' => $p->cover, 'title' => $p->title, 'subtitle' => $p->client ?: $p->location, 'link' => null,
                'group_id' => (json_decode((string) $p->categories, true) ?: [])[0] ?? null,
            ]);
        $used = $items->pluck('group_id')->filter()->unique()->flip();
        $groups = DB::table('portfolio_categories')->where('company_id', $companyId)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn ($g) => isset($used[$g->id]))->values();

        return ['groups' => $groups, 'items' => $items];
    }

    /** The section's HTML (self-contained styles/script), or '' when there's nothing to show. */
    public static function render(int $companyId, string $title, string $subtitle = '', int $limit = 24, string $id = 'mu-portfolio', bool $inStore = false): string
    {
        $d = self::data($companyId, $limit);
        if ($d['items']->isEmpty()) {
            return '';
        }

        return view('store.partials.portfolio-section', [
            'groups' => $d['groups'], 'items' => $d['items'],
            'title' => $title, 'subtitle' => $subtitle, 'sectionId' => $id, 'inStore' => $inStore,
        ])->render();
    }

    /** Put the Portfolio section into an imported front page, per its settings. */
    public static function injectInto(string $html, int $companyId): string
    {
        $cfg = self::sectionSettings($companyId);
        if (! $cfg['enabled']) {
            return $html;
        }
        $site = $cfg['placement'] === 'replace' ? self::findElementById($html, 'portfolio') : null;
        // Keep the site's own anchor (#portfolio) working when replacing.
        $section = self::render($companyId, $cfg['title'], $cfg['subtitle'], $cfg['limit'], $site ? 'portfolio' : 'mu-portfolio');
        if ($section === '') {
            return $html;
        }
        $section = "<!--murano:portfolio-->\n".$section."\n<!--/murano:portfolio-->";

        if ($site) {
            return substr_replace($html, $section, $site[0], $site[1]);
        }
        if ($cfg['placement'] !== 'end' && preg_match('~<footer\b~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return substr_replace($html, $section, $m[0][1], 0);
        }
        $at = strripos($html, '<!-- murano:store-bar -->');
        $at = $at !== false ? $at : strripos($html, '</body>');

        return $at !== false ? substr_replace($html, $section, $at, 0) : $html.$section;
    }

    /** Whether the imported front page has its own element with id="portfolio". */
    public static function siteHasPortfolioSection(int $companyId): bool
    {
        $dir = GalleryImporter::siteDir($companyId);

        return $dir && is_file($dir.'/index.html') && self::findElementById((string) file_get_contents($dir.'/index.html'), 'portfolio') !== null;
    }

    /* ------------------------------------------------------ import */

    /**
     * What import() would bring in: ['groups' => [key => label], 'items' =>
     * [['group' => key|null, 'title', 'subtitle', 'rel', 'abs']]].
     */
    public static function candidates(int $companyId): array
    {
        $dir = GalleryImporter::siteDir($companyId);
        if (! $dir || ! is_file($dir.DIRECTORY_SEPARATOR.'index.html')) {
            return ['groups' => [], 'items' => []];
        }
        $html = preg_replace('/<!--(?!\s*\/?murano).*?-->/s', '', (string) file_get_contents($dir.DIRECTORY_SEPARATOR.'index.html'));
        $labels = self::filterLabels($html);
        $items = [];

        // 1) items tagged data-type / data-category / data-filter-class
        preg_match_all('~<([a-z][a-z0-9]*)\b[^>]*\bdata-(?:type|category|cat|group)\s*=\s*["\']([^"\']+)["\'][^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($m as $hit) {
            $inner = self::innerHtml($html, $hit[0][1], strtolower($hit[1][0]));
            if ($inner !== null && ($it = self::itemFrom($inner, $dir))) {
                $items[] = $it + ['group' => trim(explode(' ', trim($hit[2][0]))[0])];
            }
        }

        // 2) tiles with class "portfolio <group>"
        if (! $items) {
            preg_match_all('~<([a-z][a-z0-9]*)\b[^>]*\bclass\s*=\s*"([^"]*\bportfolio\b[^"]*)"[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($m as $hit) {
                $classes = preg_split('/\s+/', strtolower(trim($hit[2][0])));
                if (count($classes) < 2) {
                    continue; // a container named "portfolio", not a tile
                }
                $inner = self::innerHtml($html, $hit[0][1], strtolower($hit[1][0]));
                if ($inner === null || ! ($it = self::itemFrom($inner, $dir))) {
                    continue;
                }
                // The group is the class AFTER "portfolio" ("home portfolio manicure"
                // -> manicure): the ones before it are the site's page filters.
                $after = array_slice($classes, array_search('portfolio', $classes, true) + 1);
                $group = null;
                foreach ($after as $c) {
                    if (isset($labels[$c])) {
                        $group = $c;
                        break;
                    }
                }
                $group ??= $after[0] ?? null;
                $items[] = $it + ['group' => $group];
            }
        }

        // de-dupe by picture
        $seen = [];
        $items = array_values(array_filter($items, function ($i) use (&$seen) {
            if (isset($seen[$i['rel']])) {
                return false;
            }

            return $seen[$i['rel']] = true;
        }));

        $groups = [];
        foreach ($items as $i) {
            if ($i['group'] !== null && ! isset($groups[$i['group']])) {
                $groups[$i['group']] = $labels[$i['group']] ?? self::humanize($i['group']);
            }
        }

        return ['groups' => $groups, 'items' => $items];
    }

    /**
     * Copies the imported website's portfolio into the Portfolio MODULE
     * (categories + published works with their photo), skipping pictures
     * already brought in (same file name). ['added', 'groups', 'skipped', 'found']
     */
    public static function import(int $companyId): array
    {
        $c = self::candidates($companyId);
        $relDir = CompanyFiles::dir($companyId, 'portfolio').'/imported';
        if (! is_dir(public_path($relDir))) {
            mkdir(public_path($relDir), 0755, true);
        }

        $groupIds = [];
        $newGroups = 0;
        $order = (int) DB::table('portfolio_categories')->where('company_id', $companyId)->max('sort_order');
        foreach ($c['groups'] as $key => $label) {
            $slug = Str::slug($label) ?: Str::slug($key) ?: 'group';
            $id = DB::table('portfolio_categories')->where('company_id', $companyId)
                ->where(fn ($q) => $q->where('slug', $slug)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($label)]))->value('id');
            if (! $id) {
                $id = DB::table('portfolio_categories')->insertGetId(['company_id' => $companyId, 'name' => $label, 'slug' => $slug,
                    'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now()]);
                $newGroups++;
            }
            $groupIds[$key] = $id;
        }

        // Already imported: a work whose cover came from the same site file (kept in the file name).
        $known = DB::table('portfolio_projects')->where('company_id', $companyId)->where('cover', 'like', $relDir.'/%')
            ->pluck('cover')->map(fn ($p) => preg_replace('/-[a-z0-9]{6}(\.\w+)$/', '$1', basename($p)))->flip();
        $sort = (int) DB::table('portfolio_projects')->where('company_id', $companyId)->max('sort_order');
        $added = $skipped = 0;
        foreach ($c['items'] as $i) {
            $ext = strtolower(pathinfo($i['abs'], PATHINFO_EXTENSION));
            $base = Str::limit(Str::slug(pathinfo($i['abs'], PATHINFO_FILENAME)) ?: 'photo', 60, '');
            if (isset($known[$base.'.'.$ext])) {
                $skipped++;

                continue;
            }
            $name = $base.'-'.Str::lower(Str::random(6)).'.'.$ext;
            if (! @copy($i['abs'], public_path($relDir.'/'.$name))) {
                $skipped++;

                continue;
            }
            $slug = Str::slug($i['title']) ?: 'work';
            for ($n = 2; DB::table('portfolio_projects')->where('company_id', $companyId)->where('slug', $slug)->exists(); $n++) {
                $slug = (Str::slug($i['title']) ?: 'work').'-'.$n;
            }
            $projectId = DB::table('portfolio_projects')->insertGetId([
                'company_id' => $companyId, 'title' => Str::limit($i['title'], 150, ''), 'slug' => $slug, 'type' => 'image',
                'cover' => $relDir.'/'.$name, 'description' => $i['subtitle'],
                'categories' => json_encode($i['group'] !== null && isset($groupIds[$i['group']]) ? [$groupIds[$i['group']]] : []),
                'featured' => true, 'published' => true, 'sort_order' => ++$sort, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('portfolio_media')->insert(['company_id' => $companyId, 'project_id' => $projectId, 'path' => $relDir.'/'.$name,
                'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $added++;
        }

        return ['added' => $added, 'groups' => $newGroups, 'skipped' => $skipped, 'found' => count($c['items'])];
    }

    /* ------------------------------------------------------ helpers */

    /** filter key => label, from filter lists, data-filter links and #hash menu links. */
    private static function filterLabels(string $html): array
    {
        $labels = [];
        $clean = fn ($s) => trim(html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        // <li class="catWeb"><a href="#">Web Design</a></li> inside a filter/category list
        if (preg_match_all('~<(ul|ol|div)\b[^>]*(?:id|class)\s*=\s*"[^"]*(?:filter|categor)[^"]*"[^>]*>(.*?)</\1>~is', $html, $lists)) {
            foreach ($lists[2] as $list) {
                preg_match_all('~<li\b[^>]*class\s*=\s*"([^"]*)"[^>]*>\s*<a\b[^>]*>(.*?)</a>~is', $list, $li, PREG_SET_ORDER);
                foreach ($li as $x) {
                    foreach (preg_split('/\s+/', trim($x[1])) as $cls) {
                        if ($cls !== '' && ! in_array(strtolower($cls), ['all', 'current', 'active', 'selected'], true)) {
                            $labels[$cls] = $clean($x[2]);
                        }
                    }
                }
            }
        }
        // <a data-filter=".manicure">Nails</a>
        preg_match_all('~<a\b[^>]*data-filter\s*=\s*["\']\.?([\w-]+)["\'][^>]*>(.*?)</a>~is', $html, $df, PREG_SET_ORDER);
        foreach ($df as $x) {
            if (! in_array(strtolower($x[1]), ['*', 'all'], true)) {
                $labels[strtolower($x[1])] = $clean($x[2]);
            }
        }
        // menu links <a href="#hairstyle">Hair Style</a> (only used when a tile has that class)
        preg_match_all('~<a\b[^>]*href\s*=\s*["\']#([\w-]+)["\'][^>]*>(.*?)</a>~is', $html, $hl, PREG_SET_ORDER);
        foreach ($hl as $x) {
            $k = strtolower($x[1]);
            if (! isset($labels[$k]) && ($t = $clean($x[2])) !== '' && mb_strlen($t) <= 40) {
                $labels[$k] = $t;
            }
        }

        return $labels;
    }

    /** One picture out of an item's HTML: big image (lightbox link) > <img> > CSS background. */
    private static function itemFrom(string $inner, string $dir): ?array
    {
        $cands = [];
        if (preg_match('~<a\b[^>]*href\s*=\s*["\']([^"\']+\.(?:'.self::EXT.'))(?:\?[^"\']*)?["\']~i', $inner, $a)) {
            $cands[] = $a[1];
        }
        if (preg_match('~<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']~i', $inner, $im)) {
            $cands[] = $im[1];
        }
        if (preg_match('~url\(\s*[\'"]?([^\'")]+\.(?:'.self::EXT.'))~i', $inner, $bg)) {
            $cands[] = $bg[1];
        }
        foreach ($cands as $u) {
            $u = rawurldecode(preg_replace('~[?#].*$~', '', html_entity_decode($u)));
            if (preg_match('~^([a-z][a-z0-9+.-]*:|//|data:)~i', $u)) {
                continue;
            }
            $abs = realpath(str_starts_with($u, '/') ? $dir.$u : $dir.DIRECTORY_SEPARATOR.$u);
            if ($abs && is_file($abs) && str_starts_with($abs, $dir.DIRECTORY_SEPARATOR)) {
                $clean = fn ($s) => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                preg_match_all('~<h[1-6]\b[^>]*>(.*?)</h[1-6]>~is', $inner, $h);
                $title = isset($h[1][0]) ? $clean($h[1][0]) : '';
                $subtitle = isset($h[1][1]) ? $clean($h[1][1]) : '';
                if ($title === '' && preg_match('~data-title\s*=\s*["\']([^"\']+)["\']~i', $inner, $dt)) {
                    $title = $clean($dt[1]);
                }
                if ($title === '') {
                    $title = Str::title(trim(preg_replace('/[-_]+/', ' ', pathinfo($abs, PATHINFO_FILENAME))));
                }

                return [
                    'rel' => str_replace('\\', '/', substr($abs, strlen($dir) + 1)),
                    'abs' => $abs,
                    'title' => Str::limit($title, 190, ''),
                    'subtitle' => Str::limit($subtitle, 190, '') ?: null,
                ];
            }
        }

        return null;
    }

    /** Inner HTML of the element whose opening tag starts at $start (balanced by tag name). */
    private static function innerHtml(string $html, int $start, string $tag): ?string
    {
        $el = self::elementAt($html, $start, $tag);

        return $el ? substr($html, $start, $el) : null;
    }

    /** Length of the whole element starting at $start, or null. */
    private static function elementAt(string $html, int $start, string $tag): ?int
    {
        if (in_array($tag, ['img', 'br', 'hr', 'input', 'source', 'meta', 'link'], true)) {
            return null;
        }
        preg_match_all('~<(/?)'.preg_quote($tag, '~').'\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE, $start);
        $depth = 0;
        foreach ($m[0] as $i => [$t, $pos]) {
            $depth += $m[1][$i][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return $pos + strlen($t) - $start;
            }
        }

        return null;
    }

    /** [offset, length] of the element with id="$id", or null. */
    private static function findElementById(string $html, string $id): ?array
    {
        if (! preg_match('~<([a-z][a-z0-9]*)\b[^>]*\bid\s*=\s*["\']'.preg_quote($id, '~').'["\'][^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $len = self::elementAt($html, $m[0][1], strtolower($m[1][0]));

        return $len ? [$m[0][1], $len] : null;
    }

    private static function humanize(string $key): string
    {
        $k = preg_replace('/^cat(?=[A-Z])/', '', $key);

        return Str::title(trim(preg_replace('/[-_]+|(?<=[a-z])(?=[A-Z])/', ' ', $k))) ?: $key;
    }
}
