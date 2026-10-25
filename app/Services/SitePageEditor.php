<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Edits the pages of a company's imported website (Store Settings ->
 * Homepage Blocks -> Website pages) WITHOUT re-serializing the HTML: the page
 * is split into tokens (tags / text / comments / script+style blocks), and a
 * save only rewrites the exact spots that changed -- a text run, an <img>'s
 * src/alt, an <a>'s href -- so the site's own markup, scripts and design are
 * left byte-for-byte as they were everywhere else.
 *
 * Not editable here on purpose: <head> (except the <title>), scripts/styles,
 * the navigation menu between the <!--murano-menu--> markers (Store -> Menu
 * edits that) and the Murano store bar.
 *
 * Every segment is addressed by its token index, and every save must send the
 * page `version` (md5) it was loaded with, so an edit never lands on a page
 * that changed in between (re-import, menu save, another admin).
 * The first save keeps the imported copy in storage/app/site-originals/ for
 * "Restore" (cleared when the website is imported again).
 */
class SitePageEditor
{
    private const ORIGINALS = 'site-originals';
    private const UPLOADS = 'murano-uploads';

    /** The company's imported site folder (absolute), or null. */
    public static function dir(int $companyId): ?string
    {
        return GalleryImporter::siteDir($companyId);
    }

    /** Site folder relative to public/ (for URLs). */
    public static function relDir(int $companyId): ?string
    {
        $dir = self::dir($companyId);

        return $dir ? str_replace('\\', '/', substr($dir, strlen(realpath(public_path())) + 1)) : null;
    }

    /** [['file', 'title', 'size', 'updated_at', 'edited', 'url', 'is_front']] */
    public static function pages(int $companyId): array
    {
        $dir = self::dir($companyId);
        if (! $dir) {
            return [];
        }
        $rel = self::relDir($companyId);
        $out = [];
        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.html') ?: [] as $f) {
            $html = (string) file_get_contents($f);
            $title = preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
            $out[] = [
                'file' => basename($f),
                'title' => $title,
                'size' => filesize($f),
                'updated_at' => date('Y-m-d H:i', filemtime($f)),
                'edited' => is_file(self::originalPath($companyId, basename($f))),
                'url' => url($rel.'/'.basename($f)),
                'is_front' => basename($f) === 'index.html',
            ];
        }
        usort($out, fn ($a, $b) => ($b['is_front'] <=> $a['is_front']) ?: strcmp($a['file'], $b['file']));

        return $out;
    }

    /** Absolute path of one page, validated (plain *.html file directly in the site folder). */
    public static function path(int $companyId, string $file): string
    {
        $dir = self::dir($companyId);
        abort_unless($dir && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.html?$/', $file), 404);
        $abs = $dir.DIRECTORY_SEPARATOR.$file;
        abort_unless(is_file($abs), 404);

        return $abs;
    }

    /** The editable segments of a page. */
    public static function load(int $companyId, string $file, bool $withEditHtml = false): array
    {
        $abs = self::path($companyId, $file);
        $html = (string) file_get_contents($abs);
        $seg = self::segments($html);
        $base = url(self::relDir($companyId)).'/';

        return [
            'file' => $file,
            'version' => md5($html),
            'url' => $base.$file,
            'edited' => is_file(self::originalPath($companyId, $file)),
            'texts' => array_values(array_map(fn ($s) => ['id' => $s['id'], 'text' => $s['text'], 'tag' => $s['tag']], $seg['texts'])),
            'images' => array_values(array_map(fn ($s) => [
                'id' => $s['id'], 'src' => $s['src'], 'alt' => $s['alt'], 'bg' => ! empty($s['bg']), 'popup' => ! empty($s['popup']),
                'preview' => self::previewUrl($s['src'], $base),
            ], $seg['images'])),
            'links' => array_values(array_map(fn ($s) => ['id' => $s['id'], 'href' => $s['href'], 'label' => $s['label']], $seg['links'])),
            'sections' => array_values(array_map(fn ($s) => array_diff_key($s, ['start' => 0, 'len' => 0]), self::sections($html))),
            // Murano's own "Book an appointment" page, compact layout for an iframe
            // (relative: the website is served by this same app, on any of its addresses).
            'booking_url' => self::bookingUrl($companyId),
            // Forms module (2026-10-24): the company's forms, for "Insert a form" in a section
            // (relative /f/{key}/embed frames; FrontPage::withFormResize grows them).
            'forms' => self::formsFor($companyId),
        ] + ($withEditHtml ? ['edit_html' => self::editHtml($html, $seg, $base)] : []);
    }

    /**
     * The booking frame's address for this website (relative: served by this
     * app on any of its addresses). Exit / Close go back to the company's first
     * website of Online Booking -> Websites & codes, else this website's own path.
     */
    public static function bookingUrl(int $companyId): string
    {
        $return = \App\Http\Controllers\PublicBookingController::websites($companyId)[0] ?? null;
        if (! $return && ($p = \App\Support\WebsitePaths::pathOf($companyId))) {
            $return = '/'.$p;
        }

        return '/book?'.http_build_query(['company' => $companyId, 'embed' => 1] + ($return ? ['return' => $return] : []));
    }

    /** The company's forms (Forms module, when it is on): name, published, frame address. */
    public static function formsFor(int $companyId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('forms') || ! \App\Support\ModuleFlags::enabled($companyId, 'forms')) {
            return [];
        }

        return \Illuminate\Support\Facades\DB::table('forms')->where('company_id', $companyId)->whereNull('deleted_at')->orderBy('name')
            ->get(['id', 'name', 'status', 'public_key'])
            ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'status' => (bool) $f->status, 'url' => '/f/'.$f->public_key.'/embed'])->all();
    }

    /* ------------------------------------------------------- sections */

    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    private const BLOCKS = ['section', 'article', 'div', 'aside', 'main', 'header', 'footer', 'li', 'td', 'figure', 'form', 'p'];

    /**
     * The page's sections (2026-10-07), so a one-page site can be changed
     * block by block -- e.g. an old booking calendar embed replaced by ours:
     *  - every block the page's own menu jumps to: href="#x" -> the element
     *    with id="x", or the outermost elements with class "x" (one-page
     *    filter grids such as Isotope);
     *  - every block holding an embed (an <iframe> or an inline <script>).
     * [['id' (token index), 'key', 'label', 'element', 'html', 'embeds' => [['kind', 'src']], 'start', 'len']]
     */
    public static function sections(string $html): array
    {
        $re = '~<!--.*?-->|<(script|style|noscript|textarea|template)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>.*?</\1\s*>|<[!/?a-zA-Z](?:"[^"]*"|\'[^\']*\'|[^\'">])*>|[^<]+|<~si';
        preg_match_all($re, $html, $m, PREG_OFFSET_CAPTURE);
        $tokens = $m[0];

        // Menu anchors: #x -> label (the site's menu names first, then any other link).
        $keys = [];
        $menu = preg_match('~<!--\s*murano-menu\s*-->(.*?)<!--\s*/murano-menu\s*-->~s', $html, $mm) ? $mm[1] : '';
        foreach ([$menu, $html] as $part) {
            if (preg_match_all('~<a\b[^>]*?\shref\s*=\s*["\']#([A-Za-z][\w-]*)["\'][^>]*>(.*?)</a\s*>~is', $part, $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $keys[$a[1]] ??= Str::limit(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($a[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 40) ?: $a[1];
                }
            }
        }

        // One pass with an element stack: each element's end and parent.
        $el = [];      // token index => [name, start, end, parent, raw]
        $stack = [];
        $embeds = [];  // [token index of the embed, enclosing element, kind, src]
        $inBody = false;
        $skip = 0;
        foreach ($tokens as $i => [$raw, $start]) {
            if (str_starts_with($raw, '<!--')) {
                if (preg_match('~^<!--\s*(murano-menu|murano:store-bar)\s*-->$~', $raw)) {
                    $skip++;
                } elseif (preg_match('~^<!--\s*(/murano-menu|/murano:store-bar)\s*-->$~', $raw)) {
                    $skip = max(0, $skip - 1);
                }

                continue;
            }
            if ($raw === '' || $raw[0] !== '<' || strlen($raw) < 2 || ! preg_match('~^<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9-]*)~', $raw, $t)) {
                continue;
            }
            $name = strtolower($t[2]);
            $parent = $stack ? end($stack) : null;
            if ($name === 'body' && $t[1] === '') {
                $inBody = true;
            }
            if ($name === 'script') {
                if ($inBody && ! $skip && self::attr(substr($raw, 0, strpos($raw, '>') + 1), 'src') === null) {
                    $src = preg_match('~https?://[^\s"\'<>)]+~i', $raw, $u) ? $u[0] : '';
                    $embeds[] = [$i, $parent, 'script', $src];
                }

                continue;
            }
            if (in_array($name, ['style', 'noscript', 'textarea', 'template'], true) && $t[1] === '') {
                continue;
            }
            if ($t[1] === '/') {
                $pos = null;
                for ($k = count($stack) - 1; $k >= 0; $k--) {
                    if ($el[$stack[$k]][0] === $name) {
                        $pos = $k;
                        break;
                    }
                }
                if ($pos !== null) {
                    // Elements left open inside (an unclosed <p>/<li>) end here too.
                    for ($k = count($stack) - 1; $k >= $pos; $k--) {
                        $el[$stack[$k]][2] = $k === $pos ? $start + strlen($raw) : $start;
                    }
                    $stack = array_slice($stack, 0, $pos);
                }

                continue;
            }
            $el[$i] = [$name, $start, null, $parent, $raw, $inBody && ! $skip];
            if ($name === 'iframe' && $inBody && ! $skip) {
                $embeds[] = [$i, $parent, 'iframe', (string) self::attr($raw, 'src')];
            }
            // An old HTML form (e.g. a contact form posting to a script): listed so it can
            // be swapped for one of the company's Forms (2026-10-24).
            if ($name === 'form' && $inBody && ! $skip) {
                $embeds[] = [$i, $parent, 'form', (string) self::attr($raw, 'action')];
            }
            if (! in_array($name, self::VOID, true) && ! str_ends_with(rtrim($raw, '> '), '/')) {
                $stack[] = $i;
            }
        }

        $out = [];
        $add = function (int $i, string $key, string $label) use (&$out, $el, $html) {
            [$name, $start, $end, , $raw] = $el[$i];
            if ($end === null || isset($out[$i])) {
                return;
            }
            $id = self::attr($raw, 'id');
            $class = trim((string) self::attr($raw, 'class'));
            $out[$i] = ['id' => $i, 'key' => $key, 'label' => $label,
                'element' => '<'.$name.($id ? ' id="'.$id.'"' : '').($class !== '' ? ' class="'.Str::limit($class, 60).'"' : '').'>',
                'start' => $start, 'len' => $end - $start, 'html' => substr($html, $start, $end - $start), 'embeds' => []];
        };
        $inside = function (int $i, int $j) use ($el) { // is element i inside element j?
            for ($p = $el[$i][3]; $p !== null; $p = $el[$p][3]) {
                if ($p === $j) {
                    return true;
                }
            }

            return false;
        };

        foreach ($keys as $key => $label) {
            $found = [];
            foreach ($el as $i => $e) {
                if (! $e[5] || in_array($e[0], ['a', 'span', 'img', 'html', 'body'], true)) {
                    continue;
                }
                $classes = preg_split('/\s+/', trim((string) self::attr($e[4], 'class')));
                if (self::attr($e[4], 'id') === $key || in_array($key, $classes, true)) {
                    foreach ($found as $j) {
                        if ($inside($i, $j)) {
                            continue 2;
                        }
                    }
                    $found[] = $i;
                    $add($i, $key, $label);
                }
            }
        }

        // Embeds: attach to the listed section holding them, else list their block.
        foreach ($embeds as [$ti, $parent, $kind, $src]) {
            $owner = null;
            foreach ($out as $j => $s) {
                if ($tokens[$ti][1] >= $s['start'] && $tokens[$ti][1] < $s['start'] + $s['len']) {
                    $owner = $j;
                }
            }
            if ($owner === null) {
                $b = $parent;
                while ($b !== null && ! in_array($el[$b][0], self::BLOCKS, true)) {
                    $b = $el[$b][3];
                }
                if ($b === null || ! $el[$b][5]) {
                    continue;
                }
                $add($b, '', ['iframe' => 'Embedded frame', 'form' => 'Form'][$kind] ?? 'Embedded script');
                $owner = $b;
            }
            if (isset($out[$owner])) {
                $out[$owner]['embeds'][] = ['kind' => $kind, 'src' => Str::limit($src, 200, '')];
            }
        }

        usort($out, fn ($a, $b) => $a['start'] <=> $b['start']);

        return $out;
    }

    /**
     * Replaces one section's HTML (see sections()). A company admin can't add
     * scripts or event handlers that weren't there already (the website runs
     * on the same address as the admin app); a SuperAdmin can.
     */
    public static function saveSection(int $companyId, string $file, string $version, int $id, string $new, bool $superAdmin): array
    {
        $abs = self::path($companyId, $file);
        $html = (string) file_get_contents($abs);
        if (md5($html) !== $version) {
            throw ValidationException::withMessages(['version' => 'This page changed since you opened it (re-import, menu save or another admin). Reload it and edit again.']);
        }
        $s = collect(self::sections($html))->firstWhere('id', $id);
        abort_unless($s, 404, 'That section is no longer on the page. Reload it.');
        $new = trim(str_replace("\r\n", "\n", $new));
        if ($new === trim($s['html'])) {
            return self::load($companyId, $file) + ['changed' => 0];
        }
        self::guardSection($s['html'], $new, $superAdmin);

        self::keepOriginal($abs, self::originalPath($companyId, $file));
        file_put_contents($abs, substr_replace($html, $new, $s['start'], $s['len']));

        return self::load($companyId, $file) + ['changed' => 1];
    }

    private static function guardSection(string $old, string $new, bool $superAdmin): void
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['html' => $msg]);

        // The block must stay one closed block, so the rest of the page keeps its layout.
        foreach (['div', 'section', 'article', 'ul', 'ol', 'li', 'figure', 'form', 'table', 'span', 'a', 'iframe'] as $tag) {
            $bal = fn ($h) => preg_match_all('~<'.$tag.'\b~i', $h) - preg_match_all('~</'.$tag.'\s*>~i', $h);
            if ($bal($new) !== $bal($old)) {
                $fail("Every <{$tag}> you open must be closed (and none closed that wasn't opened in this section).");
            }
        }
        if ($superAdmin) {
            return;
        }
        $set = fn ($re, $h) => preg_match_all($re, $h, $mm) ? $mm[0] : [];
        $scripts = '~<script\b.*?</script\s*>~is';
        $handlers = '~\son[a-z]+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)~i';
        if (array_diff($set($scripts, $new), $set($scripts, $old)) || preg_match_all('~<script\b~i', $new) > preg_match_all('~<script\b~i', $old)) {
            $fail('New or changed scripts can only be added by a SuperAdmin. You can remove a script, or add an <iframe> (e.g. our booking calendar).');
        }
        if (array_diff($set($handlers, $new), $set($handlers, $old))) {
            $fail('Event handlers (onclick=, onload= ...) can only be added by a SuperAdmin.');
        }
        foreach (['~(?:javascript|vbscript)\s*:~i' => 'javascript: links', '~<(?:object|embed|base|meta|link)\b~i' => '<object>, <embed>, <base>, <meta> or <link> tags', '~\ssrcdoc\s*=~i' => 'srcdoc frames'] as $re => $what) {
            if (preg_match_all($re, $new) > preg_match_all($re, $old)) {
                $fail("{$what} can only be added by a SuperAdmin.");
            }
        }
        foreach ($set('~<iframe\b[^>]*>~i', $new) as $tag) {
            $src = trim((string) self::attr($tag, 'src'));
            if ($src !== '' && ! preg_match('~^(https://|//|/(?!/)|[A-Za-z0-9._-]+\.html?\b)~i', $src)) {
                $fail('A frame must load an https:// address or a page of this website.');
            }
        }
    }

    /**
     * The page for the VISUAL editor: every editable text is wrapped in
     * <span data-mu-t="id">, pictures/links get data-mu-i / data-mu-l, and a
     * <base> makes its CSS/images load from the site folder. The admin shows
     * it in a sandboxed frame WITH the site's own scripts (so sliders/grids lay
     * out exactly as live -- the same scripts already run on this origin as
     * the live front page) but no navigation, pop-ups or form posts. Never
     * saved -- edits go through save() by id.
     */
    private static function editHtml(string $html, array $seg, string $base): string
    {
        $ops = [];
        foreach ($seg['texts'] as $s) {
            if (in_array($s['tag'], ['title', 'option', 'textarea', 'select', 'text', 'tspan'], true)) {
                continue; // can't hold a <span>; edited in the side panel / list
            }
            $ops[$s['start']] = [$s['len'], '<span data-mu-t="'.$s['id'].'">'.$s['raw'].'</span>'];
        }
        $attrs = [];
        foreach ($seg['images'] as $s) {
            $attrs[$s['id']] = [$s, ' data-mu-i="'.$s['id'].'"'];
        }
        foreach ($seg['links'] as $s) {
            $attrs[$s['id']] = [$s, ($attrs[$s['id']][1] ?? '').' data-mu-l="'.$s['id'].'"'];
        }
        foreach ($attrs as [$s, $add]) {
            $ops[$s['start']] = [$s['len'], preg_replace('~\s*(/?)>$~', $add.'$1>', $s['raw'], 1)];
        }
        krsort($ops);
        foreach ($ops as $start => [$len, $new]) {
            $html = substr_replace($html, $new, $start, $len);
        }

        // No store bar in the editor.
        $html = preg_replace('~<!--\s*murano:store-bar\s*-->.*?<!--\s*/murano:store-bar\s*-->~s', '', $html);

        $head = '<base href="'.e($base).'">'.<<<'CSS'
<style id="mu-edit-css">
  /* editor affordances */
  [data-mu-t] { cursor: text; border-radius: 2px; transition: box-shadow .12s; }
  [data-mu-t]:hover { box-shadow: 0 0 0 2px rgba(22,119,255,.55); background: rgba(22,119,255,.06); }
  [data-mu-t]:focus { outline: none; box-shadow: 0 0 0 2px #1677ff; background: rgba(255,255,255,.85); color: #111 !important; }
  [data-mu-i] { cursor: pointer; }
  img[data-mu-i]:hover, [data-mu-i]:not(img):hover { outline: 3px solid rgba(250,140,22,.9); outline-offset: -3px; }
  .mu-changed { box-shadow: 0 0 0 2px #fa8c16 !important; }
  img.mu-changed, [data-mu-i].mu-changed { outline: 3px dashed #fa8c16 !important; outline-offset: -3px; }
  .mu-selected { outline: 3px solid #1677ff !important; outline-offset: -3px; }
</style>
CSS;
        if (preg_match('~<head\b[^>]*>~i', $html, $m, PREG_OFFSET_CAPTURE)) {
            $at = $m[0][1] + strlen($m[0][0]);
            $html = substr_replace($html, $head, $at, 0);
        } else {
            $html = $head.$html;
        }

        return $html;
    }

    /**
     * Applies edits: texts {id: text}, images {id: {src?, alt?}}, links {id: href}.
     * Returns the reloaded page + how many spots changed.
     */
    public static function save(int $companyId, string $file, string $version, array $texts, array $images, array $links): array
    {
        $abs = self::path($companyId, $file);
        $html = (string) file_get_contents($abs);
        if (md5($html) !== $version) {
            throw ValidationException::withMessages(['version' => 'This page changed since you opened it (re-import, menu save or another admin). Reload it and edit again.']);
        }

        $seg = self::segments($html);
        $repl = [];

        foreach ($texts as $id => $new) {
            $s = $seg['texts'][(int) $id] ?? null;
            $new = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', (string) $new)));
            if (! $s || $new === $s['text']) {
                continue;
            }
            preg_match('/^(\s*).*?(\s*)$/su', $s['raw'], $ws);
            $repl[] = [$s['start'], $s['len'], ($ws[1] ?? '').self::encodeText($new).($ws[2] ?? '')];
        }

        // One tag can be both a picture (CSS background) and a link: collect
        // every change to a tag first, then patch that tag once.
        $tags = [];
        foreach ($images as $id => $edit) {
            $s = $seg['images'][(int) $id] ?? null;
            if (! $s || ! is_array($edit)) {
                continue;
            }
            $tag = $tags[$s['id']] ?? $s['raw'];
            $src = trim((string) ($edit['src'] ?? ''));
            if ($src !== '' && $src !== $s['src']) {
                $src = self::safeUrl($src);
                $cssUrl = "url('".str_replace("'", '%27', $src)."')";
                if (! empty($s['popup'])) {
                    $tag = self::setAttr($tag, 'href', $src);
                    if ($s['inner'] !== null) {
                        $in = $tags[$s['inner']] ?? $seg['inner'][$s['inner']]['raw'];
                        $style = preg_replace('~\s*background-image\s*:[^;]*;?~i', '', (string) self::attr($in, 'style'));
                        $tags[$s['inner']] = self::setAttr($in, 'style', trim(rtrim(trim($style), ';').'; background-image:'.$cssUrl.'; background-size:cover; background-position:center', '; '));
                    }
                } elseif (! empty($s['bg'])) {
                    $style = preg_replace('~url\(\s*([\'"]?)[^\'")]+\1\s*\)~i', $cssUrl, (string) self::attr($tag, 'style'), 1);
                    $tag = self::setAttr($tag, 'style', $style);
                } else {
                    $tag = self::setAttr($tag, 'src', $src);
                    // A lazy-loader / responsive variant would keep showing the old picture.
                    foreach (['data-src', 'data-lazy', 'data-original'] as $a) {
                        if (self::attr($tag, $a) !== null) {
                            $tag = self::setAttr($tag, $a, $src);
                        }
                    }
                    $tag = self::removeAttr(self::removeAttr($tag, 'srcset'), 'data-srcset');
                }
            }
            if (empty($s['bg']) && array_key_exists('alt', $edit) && (string) $edit['alt'] !== $s['alt']) {
                // A lightbox tile's caption is its data-title (the popup shows it).
                $tag = self::setAttr($tag, ! empty($s['popup']) ? (self::attr($tag, 'title') !== null && self::attr($tag, 'data-title') === null ? 'title' : 'data-title') : 'alt',
                    Str::limit(trim((string) $edit['alt']), 250, ''));
            }
            $tags[$s['id']] = $tag;
        }

        foreach ($links as $id => $href) {
            $s = $seg['links'][(int) $id] ?? null;
            $href = trim((string) $href);
            if (! $s || $href === $s['href']) {
                continue;
            }
            $tags[$s['id']] = self::setAttr($tags[$s['id']] ?? $s['raw'], 'href', self::safeUrl($href));
        }

        foreach ($tags as $id => $tag) {
            $s = $seg['images'][$id] ?? $seg['links'][$id] ?? $seg['inner'][$id];
            if ($tag !== $s['raw']) {
                $repl[] = [$s['start'], $s['len'], $tag];
            }
        }

        if ($repl) {
            usort($repl, fn ($a, $b) => $b[0] <=> $a[0]);
            foreach ($repl as [$start, $len, $new]) {
                $html = substr_replace($html, $new, $start, $len);
            }
            self::keepOriginal($abs, self::originalPath($companyId, $file));
            file_put_contents($abs, $html);
        }

        return self::load($companyId, $file) + ['changed' => count($repl)];
    }

    /** Puts the imported version back (undoes every edit of this page). */
    public static function restore(int $companyId, string $file): array
    {
        $abs = self::path($companyId, $file);
        $orig = self::originalPath($companyId, $file);
        abort_unless(is_file($orig), 422, 'This page has no edits to undo.');
        copy($orig, $abs);
        @unlink($orig);

        return self::load($companyId, $file);
    }

    /** Stores an uploaded picture inside the site folder; returns its page-relative path. */
    public static function storeUpload(int $companyId, \Illuminate\Http\UploadedFile $file): string
    {
        $dir = self::dir($companyId);
        abort_unless($dir, 404);
        $target = $dir.DIRECTORY_SEPARATOR.self::UPLOADS;
        if (! is_dir($target)) {
            mkdir($target, 0755, true);
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = Str::limit(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'image', 60, '').'-'.Str::lower(Str::random(6)).'.'.$ext;
        $file->move($target, $name);

        return self::UPLOADS.'/'.$name;
    }

    /* ------------------------------------------------------------------ */

    /**
     * texts / images / links keyed by token index, each with its byte
     * offset + length in the page so a save can patch just that spot.
     */
    private static function segments(string $html): array
    {
        $re = '~<!--.*?-->|<(script|style|noscript|textarea|template)\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>.*?</\1\s*>|<[!/?a-zA-Z](?:"[^"]*"|\'[^\']*\'|[^\'">])*>|[^<]+|<~si';
        preg_match_all($re, $html, $m, PREG_OFFSET_CAPTURE);

        $texts = $images = $links = $inner = [];
        $inHead = false;
        $inTitle = false;
        $skip = 0; // inside the managed menu / store bar
        $tag = '';
        $openLink = null;
        foreach ($m[0] as $i => [$raw, $start]) {
            if (str_starts_with($raw, '<!--')) {
                if (preg_match('~^<!--\s*(murano-menu|murano:store-bar)\s*-->$~', $raw)) {
                    $skip++;
                } elseif (preg_match('~^<!--\s*(/murano-menu|/murano:store-bar)\s*-->$~', $raw)) {
                    $skip = max(0, $skip - 1);
                }

                continue;
            }
            if ($raw[0] === '<' && strlen($raw) > 1) {
                if (! preg_match('~^<\s*(/?)\s*([a-zA-Z][a-zA-Z0-9-]*)~', $raw, $t)) {
                    continue;
                }
                $close = $t[1] === '/';
                $name = strtolower($t[2]);
                if (in_array($name, ['script', 'style', 'noscript', 'textarea', 'template'], true)) {
                    continue;
                }
                if ($name === 'head') {
                    $inHead = ! $close;
                } elseif ($name === 'body' && ! $close) {
                    $inHead = false;
                } elseif ($name === 'title') {
                    $inTitle = ! $close;
                }
                if (! $close) {
                    $tag = $name;
                }
                if ($skip || $inHead) {
                    continue;
                }
                if (! $close && $name === 'img' && ($src = self::attr($raw, 'src') ?? self::attr($raw, 'data-src')) !== null) {
                    $images[$i] = ['id' => $i, 'start' => $start, 'len' => strlen($raw), 'raw' => $raw,
                        'src' => $src, 'alt' => (string) self::attr($raw, 'alt')];
                    // A thumbnail <img> inside a lightbox link is the photo itself.
                    if ($openLink !== null) {
                        unset($images[$openLink]);
                    }
                } elseif (! $close && $name === 'a' && ($href = self::attr($raw, 'href')) !== null) {
                    $links[$i] = ['id' => $i, 'start' => $start, 'len' => strlen($raw), 'raw' => $raw, 'href' => $href, 'label' => ''];
                    $openLink = $i;
                    // Lightbox photo tile: <a href="big.jpg"><figure class="bg-from-css">.
                    // Replacing it changes the link AND paints the new picture on
                    // the tile's first inner element (inline style beats the CSS class).
                    if (preg_match('~\.(jpe?g|png|webp|gif)(\?.*)?$~i', $href)) {
                        $images[$i] = ['id' => $i, 'start' => $start, 'len' => strlen($raw), 'raw' => $raw,
                            'src' => $href, 'alt' => (string) (self::attr($raw, 'data-title') ?? self::attr($raw, 'title') ?? ''), 'popup' => true, 'inner' => null];
                    }
                } elseif ($close && $name === 'a') {
                    $openLink = null;
                } elseif (! $close && $openLink !== null && isset($images[$openLink]['popup']) && $images[$openLink]['inner'] === null) {
                    $images[$openLink]['inner'] = $i;
                    $inner[$i] = ['id' => $i, 'start' => $start, 'len' => strlen($raw), 'raw' => $raw];
                }
                // Photo tiles drawn as inline CSS backgrounds (style="background-image:url(...)").
                if (! $close && $name !== 'img' && ($style = self::attr($raw, 'style')) !== null
                    && preg_match('~url\(\s*([\'"]?)([^\'")]+)\1\s*\)~i', $style, $u) && ! str_starts_with(trim($u[2]), 'data:')) {
                    $images[$i] = ['id' => $i, 'start' => $start, 'len' => strlen($raw), 'raw' => $raw,
                        'src' => trim($u[2]), 'alt' => '', 'bg' => true];
                }

                continue;
            }

            // text
            if ($skip || ($inHead && ! $inTitle)) {
                continue;
            }
            $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($text === '' || ! preg_match('/\p{L}/u', $text)) {
                continue;
            }
            $texts[$i] = ['id' => $i, 'start' => $start, 'len' => strlen($raw), 'raw' => $raw, 'text' => $text, 'tag' => $inTitle ? 'title' : $tag];
            if ($openLink !== null && $links[$openLink]['label'] === '') {
                $links[$openLink]['label'] = Str::limit($text, 60);
            }
        }

        return compact('texts', 'images', 'links', 'inner');
    }

    private static function attr(string $tag, string $name): ?string
    {
        if (preg_match('~\s'.preg_quote($name, '~').'\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i', $tag, $m)) {
            return html_entity_decode($m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return null;
    }

    private static function setAttr(string $tag, string $name, string $value): string
    {
        $enc = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $re = '~(\s'.preg_quote($name, '~').'\s*=\s*)(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)~i';
        if (preg_match($re, $tag)) {
            return preg_replace_callback($re, fn ($m) => $m[1].'"'.$enc.'"', $tag, 1);
        }

        return preg_replace('~\s*(/?)>$~', ' '.$name.'="'.$enc.'"$1>', $tag, 1);
    }

    private static function removeAttr(string $tag, string $name): string
    {
        return preg_replace('~\s'.preg_quote($name, '~').'\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s"\'>]+)~i', '', $tag);
    }

    private static function encodeText(string $s): string
    {
        return htmlspecialchars($s, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Blocks script-y URLs; everything else (relative, #, /, https, mailto, tel) is fine. */
    private static function safeUrl(string $u): string
    {
        if (preg_match('~^\s*(javascript|vbscript|data)\s*:~i', $u) && ! preg_match('~^data:image/~i', $u)) {
            throw ValidationException::withMessages(['url' => 'That link type is not allowed.']);
        }

        return $u;
    }

    private static function previewUrl(string $src, string $base): string
    {
        if ($src === '' || preg_match('~^([a-z][a-z0-9+.-]*:|//)~i', $src)) {
            return $src;
        }

        return str_starts_with($src, '/') ? url($src) : $base.ltrim($src, './');
    }

    /**
     * The imported copy of a page, kept on its first edit -- OUTSIDE the
     * public site folder (storage/), so the menu editor, the gallery import
     * and visitors never see it.
     */
    private static function originalPath(int $companyId, string $file): string
    {
        return storage_path('app/'.self::ORIGINALS.'/company_'.$companyId.'/'.basename($file));
    }

    /** Drops every kept original (a fresh import replaces the pages). */
    public static function forgetOriginals(int $companyId): void
    {
        \Illuminate\Support\Facades\File::deleteDirectory(storage_path('app/'.self::ORIGINALS.'/company_'.$companyId));
    }

    private static function keepOriginal(string $abs, string $orig): void
    {
        if (! is_dir(dirname($orig))) {
            mkdir(dirname($orig), 0755, true);
        }
        if (! is_file($orig)) {
            copy($abs, $orig);
        }
    }
}
