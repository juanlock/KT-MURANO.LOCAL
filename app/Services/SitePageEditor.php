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
        ] + ($withEditHtml ? ['edit_html' => self::editHtml($html, $seg, $base)] : []);
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
