<?php

namespace App\Services;

use App\Models\StoreSetting;

/**
 * The navigation menu of a company's IMPORTED front page (Store Settings ->
 * Front page = imported), editable from Store -> Menus.
 *
 * detect(): finds the site's main menu <ul> (the first <ul> inside a <nav>,
 * or a <ul> whose class/id says nav/menu, with 3+ links), marks it -- and
 * every copy of it (same links) in every page of the imported site -- with
 * <!--murano-menu--> ... <!--/murano-menu-->, and reads it into an editable
 * two-level list. Each item keeps the site's own markup as a template (li/a
 * attributes, the label's wrapper such as <span>, the sub-menu wrapper), so
 * rebuilt menus keep the site's design and scripts (e.g. class="splink").
 *
 * apply(): rebuilds every marked menu in every page from the edited list.
 * Stored in store_settings.imported_menu.
 */
class FrontPageMenu
{
    private const START = '<!--murano-menu-->';
    private const END = '<!--/murano-menu-->';

    /* ------------------------------------------------------------ public */

    public static function get(int $companyId): array
    {
        $s = StoreSetting::forCompany($companyId);
        $menu = is_array($s->imported_menu) ? $s->imported_menu : (json_decode((string) $s->imported_menu, true) ?: null);

        // The site's markup templates stay server-side: the editor only sees
        // (and sends back) id / label / href / children.
        $public = function (array $items) use (&$public) {
            return array_map(fn ($i) => [
                'id' => $i['id'] ?? null,
                'label' => $i['label'],
                'href' => $i['href'],
                // Spanish pages (2026-11-01): their own label / link ('' = the label above / the -es twin page).
                'label_es' => $i['label_es'] ?? '',
                'href_es' => $i['href_es'] ?? '',
                'children' => $public($i['children'] ?? []),
            ], $items);
        };

        return [
            'available' => $s->front_page_mode === 'imported' && $s->front_page_path,
            'detected' => (bool) $menu,
            'items' => $public($menu['items'] ?? []),
            'pages' => $menu['pages'] ?? 0,
        ];
    }

    /** Stable ids ("m3", "m3-1") so edits can find each item's template. */
    private static function withIds(array $items, string $prefix = 'm'): array
    {
        foreach ($items as $n => &$i) {
            $i['id'] = $prefix.$n;
            $i['children'] = self::withIds($i['children'] ?? [], $prefix.$n.'-');
        }

        return $items;
    }

    /** id => tpl for every stored item. */
    private static function templates(array $items, array &$map = []): array
    {
        foreach ($items as $i) {
            if (! empty($i['id'])) {
                $map[$i['id']] = $i['tpl'] ?? [];
            }
            self::templates($i['children'] ?? [], $map);
        }

        return $map;
    }

    /** (Re)detect the menu from the imported site. Returns items or []. */
    public static function detect(int $companyId): array
    {
        $s = StoreSetting::forCompany($companyId);
        if ($s->front_page_mode !== 'imported' || ! $s->front_page_path || ! is_file(public_path($s->front_page_path))) {
            return [];
        }
        $entry = public_path($s->front_page_path);
        $dir = dirname($entry);
        $html = self::stripMarkers(file_get_contents($entry));

        $range = self::findMainMenu($html);
        if (! $range) {
            $s->forceFill(['imported_menu' => null])->save();

            return [];
        }
        [$openEnd, $closeStart] = $range;
        $inner = substr($html, $openEnd, $closeStart - $openEnd);
        $items = self::withIds(self::parseItems($inner));

        // Mark the menu in every page of the site. Each page's own main menu
        // is detected (sub-pages often differ slightly from the home page,
        // e.g. a home-only section link), plus identical copies of it on the
        // same page (sticky / mobile headers).
        $pages = 0;
        foreach (self::htmlFiles($dir) as $file) {
            $doc = self::stripMarkers(file_get_contents($file));
            $pageRange = self::findMainMenu($doc);
            if (! $pageRange) {
                file_put_contents($file, $doc);
                continue;
            }
            $sig = self::signature(self::parseItems(substr($doc, $pageRange[0], $pageRange[1] - $pageRange[0])));
            $count = 0;
            $doc = self::markCopies($doc, $sig, $count);
            if ($count) {
                $pages++;
            }
            file_put_contents($file, $doc);
        }

        $s->forceFill(['imported_menu' => json_encode(['items' => $items, 'pages' => $pages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])->save();

        return $items;
    }

    /** Save edited items and rebuild every marked menu. Returns pages updated. */
    public static function apply(int $companyId, array $items): int
    {
        $s = StoreSetting::forCompany($companyId);
        $current = json_decode((string) $s->getRawOriginal('imported_menu'), true) ?: [];
        $items = self::clean($items, $current['items'] ?? []);
        $entry = public_path($s->front_page_path);
        $dir = dirname($entry);

        $pages = 0;
        foreach (self::htmlFiles($dir) as $file) {
            // On other pages, a section link ("#about") must go back to the
            // front page ("index.html#about") -- the section lives there.
            $isEntry = realpath($file) === realpath($entry);
            $depth = substr_count(str_replace('\\', '/', substr($file, strlen($dir) + 1)), '/');
            $doc = file_get_contents($file);
            // A Spanish page (name-es.html or <html lang="es">) gets the Spanish labels and the
            // -es twin of each local page (2026-11-01: KcolTech's Spanish pages showed the English menu).
            $es = self::isSpanish($file, $doc);
            $entryName = basename($entry);
            if ($es) {
                $twin = self::esTwin($entryName, $dir);
                $entryName = $twin ?: $entryName;
                $isEntry = $isEntry || realpath($file) === realpath($dir.'/'.$entryName);
            }
            $home = str_repeat('../', $depth).$entryName;
            $pageItems = $es ? self::spanish($items, $dir) : $items;
            $html = self::render($isEntry ? $pageItems : self::pointSectionsHome($pageItems, $home));

            $new = preg_replace_callback('/'.preg_quote(self::START, '/').'.*?'.preg_quote(self::END, '/').'/s',
                fn () => self::START.$html.self::END, $doc, -1, $n);
            if ($n) {
                file_put_contents($file, $new);
                $pages++;
            }
        }
        $s->forceFill(['imported_menu' => json_encode(['items' => $items, 'pages' => $pages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)])->save();

        return $pages;
    }

    /* ------------------------------------------------------- detection */

    private static function htmlFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->isFile() && preg_match('/\.html?$/i', $f->getFilename())) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }

    private static function stripMarkers(string $html): string
    {
        return str_replace([self::START, self::END], '', $html);
    }

    /** [start, end) ranges of HTML comments (disabled markup lives there). */
    private static function commentRanges(string $html): array
    {
        preg_match_all('/<!--(?!\/?murano-menu-->).*?-->/s', $html, $m, PREG_OFFSET_CAPTURE);

        return array_map(fn ($c) => [$c[1], $c[1] + strlen($c[0])], $m[0]);
    }

    private static function inRanges(int $pos, array $ranges): bool
    {
        foreach ($ranges as [$s, $e]) {
            if ($pos >= $s && $pos < $e) {
                return true;
            }
        }

        return false;
    }

    /** [innerStart, innerEnd] of the main menu <ul>, or null. */
    private static function findMainMenu(string $html): ?array
    {
        $candidates = [];
        $comments = self::commentRanges($html);
        preg_match_all('/<ul\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$tag, $pos]) {
            if (self::inRanges($pos, $comments)) {
                continue; // commented-out (disabled) menu
            }
            $range = self::balanced($html, $pos + strlen($tag));
            if (! $range) {
                continue;
            }
            $inner = substr($html, $pos + strlen($tag), $range - ($pos + strlen($tag)));
            $links = count(self::parseItems($inner));
            if ($links < 3 || $links > 20) {
                continue;
            }
            $before = substr($html, max(0, $pos - 400), min(400, $pos));
            $inNav = preg_match('/<nav\b[^>]*>(?:(?!<\/nav>).)*$/is', $before);
            $named = preg_match('/(class|id)="[^"]*(nav|menu)[^"]*"/i', $tag);
            if (! $inNav && ! $named) {
                continue;
            }
            // Skip lists nested inside an earlier candidate (sub-menus).
            foreach ($candidates as [$s, $e]) {
                if ($pos > $s && $pos < $e) {
                    continue 2;
                }
            }
            $candidates[] = [$pos + strlen($tag), $range];
        }

        return $candidates[0] ?? null;
    }

    /** Offset of the </ul> closing the <ul> whose content starts at $from. */
    private static function balanced(string $html, int $from): ?int
    {
        $depth = 1;
        $offset = $from;
        while ($depth > 0 && preg_match('/<(\/?)ul\b[^>]*>/i', $html, $mm, PREG_OFFSET_CAPTURE, $offset)) {
            $depth += $mm[1][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return $mm[0][1];
            }
            $offset = $mm[0][1] + strlen($mm[0][0]);
        }

        return null;
    }

    /** Menu items from a <ul>'s inner HTML (two levels), with markup templates. */
    private static function parseItems(string $inner): array
    {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8"?><ul id="__root">'.$inner.'</ul>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $root = $dom->getElementById('__root') ?: $dom->getElementsByTagName('ul')->item(0);
        if (! $root) {
            return [];
        }

        return self::readList($dom, $root, 0);
    }

    private static function readList(\DOMDocument $dom, \DOMElement $ul, int $depth): array
    {
        $items = [];
        foreach ($ul->childNodes as $li) {
            if (! $li instanceof \DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }
            $a = null;
            foreach ($li->childNodes as $c) {
                if ($c instanceof \DOMElement && strtolower($c->tagName) === 'a') {
                    $a = $c;
                    break;
                }
            }
            if (! $a) {
                continue;
            }
            $label = trim(preg_replace('/\s+/', ' ', $a->textContent));
            $innerTpl = self::innerHtml($dom, $a);
            $innerTpl = $label !== '' ? str_replace(htmlspecialchars($label, ENT_QUOTES | ENT_HTML5), '{{label}}', preg_replace('/\s+/', ' ', $innerTpl)) : '{{label}}';
            if (! str_contains($innerTpl, '{{label}}')) {
                $innerTpl = '{{label}}';
            }

            $item = [
                'label' => $label,
                'href' => $a->getAttribute('href'),
                'tpl' => [
                    'li' => self::attrs($li, []),
                    'a' => self::attrs($a, ['href']),
                    'inner' => $innerTpl,
                ],
                'children' => [],
            ];

            // Sub-menu: the first nested <ul>, with whatever wraps it.
            $sub = null;
            foreach ($li->getElementsByTagName('ul') as $u) {
                $sub = $u;
                break;
            }
            if ($sub && $depth === 0) {
                $open = '';
                $close = '';
                for ($n = $sub; $n && $n !== $li; $n = $n->parentNode) {
                    $open = '<'.$n->tagName.self::attrs($n, []).'>'.$open;
                    $close .= '</'.$n->tagName.'>';
                }
                $item['tpl']['sub_open'] = $open;
                $item['tpl']['sub_close'] = $close;
                $item['children'] = self::readList($dom, $sub, 1);
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function attrs(\DOMElement $el, array $skip): string
    {
        $out = '';
        foreach ($el->attributes as $at) {
            if (! in_array(strtolower($at->name), $skip, true)) {
                $out .= ' '.$at->name.'="'.htmlspecialchars($at->value, ENT_QUOTES).'"';
            }
        }

        return $out;
    }

    private static function innerHtml(\DOMDocument $dom, \DOMElement $el): string
    {
        $html = '';
        foreach ($el->childNodes as $c) {
            $html .= $dom->saveHTML($c);
        }

        return trim($html);
    }

    private static function signature(array $items): string
    {
        return implode('|', array_map(fn ($i) => $i['href'], $items));
    }

    /** Mark every <ul> whose items match the main menu's signature. */
    private static function markCopies(string $html, string $signature, int &$count): string
    {
        $offset = 0;
        while (preg_match('/<ul\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $openEnd = $m[0][1] + strlen($m[0][0]);
            if (self::inRanges($m[0][1], self::commentRanges($html))) {
                $offset = $openEnd; // never touch commented-out markup

                continue;
            }
            $close = self::balanced($html, $openEnd);
            if ($close === null) {
                break;
            }
            $inner = substr($html, $openEnd, $close - $openEnd);
            if (self::signature(self::parseItems($inner)) === $signature) {
                $html = substr($html, 0, $openEnd).self::START.$inner.self::END.substr($html, $close);
                $count++;
                $offset = $close + strlen(self::START) + strlen(self::END);
            } else {
                $offset = $openEnd;
            }
        }

        return $html;
    }

    /* --------------------------------------------------------- editing */

    /**
     * Validate edited items. Each keeps the site markup of the stored item
     * with the same id; new items (no/unknown id) copy a sibling's markup.
     * Templates are never taken from the request.
     */
    private static function clean(array $items, array $original): array
    {
        $topTpl = $original[0]['tpl'] ?? ['li' => '', 'a' => '', 'inner' => '{{label}}'];
        $withSub = collect($original)->first(fn ($i) => ! empty($i['tpl']['sub_open']));
        $childTpl = $withSub['children'][0]['tpl'] ?? $topTpl;
        $known = self::templates($original);
        // Default item markup: the site's plain item, minus "active" state.
        $plain = function (array $tpl) {
            $tpl['li'] = preg_replace('/\s*class="[^"]*\b(uk-active|active|current[^"\s]*)\b[^"]*"/i', '', $tpl['li'] ?? '');
            unset($tpl['sub_open'], $tpl['sub_close']);

            return $tpl;
        };

        $seq = 0;
        $mk = function (array $i, array $fallbackTpl, bool $top, string $prefix) use (&$mk, $withSub, $childTpl, $known, $plain, &$seq) {
            $id = (string) ($i['id'] ?? '');
            $tpl = $known[$id] ?? $plain($fallbackTpl);
            $newId = $id !== '' && isset($known[$id]) ? $id : $prefix.'n'.(++$seq);
            $children = [];
            if ($top) {
                foreach (array_slice((array) ($i['children'] ?? []), 0, 40) as $c) {
                    $children[] = $mk((array) $c, $childTpl, false, $newId.'-');
                }
                if ($children && empty($tpl['sub_open']) && $withSub) {
                    $tpl['sub_open'] = $withSub['tpl']['sub_open'];
                    $tpl['sub_close'] = $withSub['tpl']['sub_close'];
                }
            }

            return [
                'id' => $newId,
                'label' => mb_substr(trim((string) ($i['label'] ?? '')), 0, 80),
                // Empty = no href (e.g. an onclick-only link like KcolTech's "Spanish").
                'href' => mb_substr(trim((string) ($i['href'] ?? '')), 0, 500),
                'label_es' => mb_substr(trim((string) ($i['label_es'] ?? '')), 0, 80),
                'href_es' => mb_substr(trim((string) ($i['href_es'] ?? '')), 0, 500),
                'tpl' => array_intersect_key($tpl, array_flip(['li', 'a', 'inner', 'sub_open', 'sub_close'])),
                'children' => $children,
            ];
        };

        return array_values(array_filter(
            array_map(fn ($i) => $mk((array) $i, $topTpl, true, 'm'), array_slice($items, 0, 40)),
            fn ($i) => $i['label'] !== ''
        ));
    }

    private static function isSpanish(string $file, string $doc): bool
    {
        return (bool) preg_match('/-es\.html?$/i', basename($file))
            || (bool) preg_match('/<html\b[^>]*\blang=["\']es/i', substr($doc, 0, 2000));
    }

    /** "about.html" -> "about-es.html" when that file exists in the site, else null. */
    private static function esTwin(string $page, string $dir): ?string
    {
        if (! preg_match('/^(.+?)(\.html?)$/i', $page, $m) || preg_match('/-es$/i', $m[1])) {
            return null;
        }
        $twin = $m[1].'-es'.$m[2];

        return is_file($dir.'/'.$twin) ? $twin : null;
    }

    /** The items as shown on a Spanish page: label_es / href_es, else the -es twin of a local page. */
    private static function spanish(array $items, string $dir): array
    {
        return array_map(function ($i) use ($dir) {
            $i['label'] = trim((string) ($i['label_es'] ?? '')) ?: $i['label'];
            if (trim((string) ($i['href_es'] ?? '')) !== '') {
                $i['href'] = $i['href_es'];
            } elseif (preg_match('~^([^:/?#][^:?#]*\.html?)(#.*)?$~i', (string) $i['href'], $m) && ($twin = self::esTwin($m[1], $dir))) {
                $i['href'] = $twin.($m[2] ?? '');
            }
            $i['children'] = self::spanish($i['children'] ?? [], $dir);

            return $i;
        }, $items);
    }

    private static function pointSectionsHome(array $items, string $home): array
    {
        return array_map(function ($i) use ($home) {
            if (preg_match('/^#[A-Za-z]/', $i['href'])) {
                $i['href'] = $home.$i['href'];
            }
            $i['children'] = self::pointSectionsHome($i['children'] ?? [], $home);

            return $i;
        }, $items);
    }

    private static function render(array $items): string
    {
        $out = '';
        foreach ($items as $i) {
            $out .= self::renderItem($i, true);
        }

        return $out;
    }

    private static function renderItem(array $i, bool $top): string
    {
        $tpl = $i['tpl'] ?? [];
        // href is ours (escaped); the li/a attributes come from the site.
        $href = htmlspecialchars($i['href'], ENT_QUOTES);
        $label = str_replace('{{label}}', htmlspecialchars($i['label'], ENT_QUOTES), $tpl['inner'] ?? '{{label}}');
        $html = '<li'.($tpl['li'] ?? '').'><a'.($href !== '' ? ' href="'.$href.'"' : '').($tpl['a'] ?? '').'>'.$label.'</a>';
        if ($top && ! empty($i['children'])) {
            $html .= ($tpl['sub_open'] ?? '<ul>');
            foreach ($i['children'] as $c) {
                $html .= self::renderItem($c, false);
            }
            $html .= ($tpl['sub_close'] ?? '</ul>');
        }

        return $html."</li>\n";
    }
}
