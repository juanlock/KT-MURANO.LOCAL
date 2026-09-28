<?php

// Import the NewEra Unisex Salon website (D:\WEBSITES-LOCAL\NEWERA.LOCAL,
// served at http://newera.local/) as company 3's "Home" page in
// Store Management -> Pages (slug `home`), converted to editable HTML.
//
// - Parses index.html's tile grid (#container .element): service boxes
//   (title, includes list, price), studio contact, booking / Facebook call-
//   outs and photo tiles (their photo comes from styles.css
//   .background-imageN rules; the popup's large photo is used when present).
// - Copies only the images the page uses into
//   public/files/company_3/store/pages/home/ (CompanyFiles convention).
// - Upserts the page for company 3 (re-running refreshes it).
//
// (Other companies' sites use the generic App\Services\SiteImporter --
// fromDirectory() for static folders, fromUrl() for live PHP/CMS sites.)
//
// Run via tinker (absolute path):
// php artisan tinker --execute="require 'D:\WEBSITES-LOCAL\KT-MURANO.LOCAL\database\scripts\import_newera_home.php';"

use App\Models\StorePage;
use App\Support\CompanyFiles;

$companyId = 3;
$siteDir = 'D:\\WEBSITES-LOCAL\\NEWERA.LOCAL';
$html = file_get_contents($siteDir.'\\index.html');
$css = file_get_contents($siteDir.'\\css\\styles.css');

// .background-imageN -> images/xxx.jpg
preg_match_all('/\.background-image(\d+)\s*\{[^}]*url\(\.\.\/(images\/[^)]+)\)/', $css, $m, PREG_SET_ORDER);
$bgMap = [];
foreach ($m as $row) {
    $bgMap['background-image'.$row[1]] = $row[2];
}

$relDir = CompanyFiles::dir($companyId, 'store/pages/home');
$copied = [];
$publish = function (?string $siteRel) use ($siteDir, $relDir, &$copied): ?string {
    if (! $siteRel || preg_match('#^https?://#', $siteRel)) {
        return null;
    }
    $src = $siteDir.'\\'.str_replace('/', '\\', $siteRel);
    if (! is_file($src)) {
        return null;
    }
    $name = basename($siteRel);
    $dest = public_path($relDir.'/'.$name);
    if (! is_file($dest)) {
        copy($src, $dest);
    }
    $copied[$name] = true;

    return '/'.$relDir.'/'.$name;
};

$e = fn ($s) => htmlspecialchars(trim(preg_replace('/\s+/', ' ', (string) $s)), ENT_QUOTES, 'UTF-8');

libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
$xp = new DOMXPath($dom);
$text = function (?DOMNode $n) { return $n ? trim(preg_replace('/\s+/', ' ', $n->textContent)) : ''; };
$first = function (DOMNode $ctx, string $q) use ($xp) { $r = $xp->query($q, $ctx); return $r && $r->length ? $r->item(0) : null; };

$title = $text($xp->query('//h1[@id="logo"]')->item(0)) ?: 'New Era Unisex Salon';
$tagline = $text($xp->query('//div[@id="content"]//h4')->item(0));

// The site shows every tile (isotope filter '*'), including leftovers of the
// template it was built on -- blog teasers, shop samples, "commercial" demo
// tiles and empty about/teaser boxes. Keep only the salon's own tiles.
$skipClasses = ['shop', 'blog', 'teaser', 'commercial', 'about', 'booking'];

$tiles = [];
foreach ($xp->query('//div[@id="container"]/div[contains(concat(" ", normalize-space(@class), " "), " element ")]') as $el) {
    $classes = preg_split('/\s+/', trim($el->getAttribute('class')));
    if (array_intersect($classes, $skipClasses)) {
        continue;
    }
    $fig = $first($el, './/figure[contains(@class,"images")]');
    if ($fig) {
        // Photo tile.
        $bgClass = collect(explode(' ', $fig->getAttribute('class')))->first(fn ($c) => str_starts_with($c, 'background-image'));
        $link = $first($el, './/a[@href]');
        $img = $publish($bgMap[$bgClass] ?? null);
        $big = $link ? $publish($link->getAttribute('href')) : null;
        $tiles[] = [
            'type' => 'photo',
            'img' => $img ?: $big,
            'big' => $big ?: $img,
            'kicker' => $text($first($el, './/p[contains(@class,"small")]')),
            'title' => $text($first($el, './/h4')) ?: $link?->getAttribute('data-title'),
            'caption' => $text($first($el, './/div[contains(@class,"image-caption")]')),
        ];
        continue;
    }
    // Text tile (service / contact / call-out).
    $h = $first($el, './/*[self::h3 or self::h5][contains(@class,"header")]');
    $items = [];
    foreach ($xp->query('.//ul/li', $el) as $li) {
        $items[] = $text($li);
    }
    $links = [];
    foreach ($xp->query('.//a[@href]', $el) as $a) {
        $links[] = ['href' => $a->getAttribute('href'), 'text' => $text($a) ?: 'Open'];
    }
    $paras = [];
    foreach ($xp->query('.//p[not(contains(@class,"price"))]', $el) as $p) {
        if (! $xp->query('.//a', $p)->length) {
            $paras[] = ['small' => str_contains($p->getAttribute('class'), 'small'), 'text' => $text($p)];
        }
    }
    $price = $text($first($el, './/p[contains(@class,"price")]'));
    if (trim($text($h), " -*") === '') {
        continue; // untitled leftover box (map placeholder etc.)
    }
    $tiles[] = [
        'type' => 'text',
        'title' => trim($text($h), " -*"),
        'paras' => array_values(array_filter($paras, fn ($p) => $p['text'] !== '')),
        'items' => $items,
        'price' => $price,
        'links' => $links,
    ];
}

// ---------------------------------------------------------------- render
$out = [];
$out[] = '<div style="text-align:center;padding:28px 16px;border-radius:16px;background:linear-gradient(135deg,#111827,#374151);color:#fff;margin-bottom:20px">';
$out[] = '<h1 style="color:#fff;font-size:2rem;margin:0 0 6px;letter-spacing:.04em">'.$e($title).'</h1>';
if ($tagline) {
    $out[] = '<p style="margin:0;opacity:.85">'.$e($tagline).'</p>';
}
$out[] = '</div>';
$out[] = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:16px">';
foreach ($tiles as $t) {
    if ($t['type'] === 'photo' && $t['img']) {
        $out[] = '<a href="'.$e($t['big']).'" target="_blank" style="display:block;position:relative;border-radius:14px;overflow:hidden;min-height:220px;text-decoration:none;background:#000">'
            .'<img src="'.$e($t['img']).'" alt="'.$e($t['title']).'" style="width:100%;height:220px;object-fit:cover;display:block;margin:0;border-radius:0;opacity:.85">'
            .'<div style="position:absolute;left:0;right:0;bottom:0;padding:12px 14px;background:linear-gradient(transparent,rgba(0,0,0,.75));color:#fff">'
            .($t['kicker'] ? '<div style="font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;opacity:.85">'.$e($t['kicker']).'</div>' : '')
            .'<div style="font-weight:700;font-size:1.05rem">'.$e($t['title']).'</div>'
            .($t['caption'] ? '<div style="font-size:.8rem;opacity:.8">'.$e($t['caption']).'</div>' : '')
            .'</div></a>';
    } elseif ($t['type'] === 'text' && ($t['title'] || $t['paras'] || $t['items'])) {
        $box = '<div style="border:1px solid rgba(128,128,128,.25);border-radius:14px;padding:18px;text-align:center">';
        if ($t['title']) {
            $box .= '<h3 style="margin:0 0 10px">'.$e($t['title']).'</h3>';
        }
        foreach ($t['paras'] as $p) {
            $box .= $p['small']
                ? '<p style="margin:8px 0 2px;font-size:.75rem;text-transform:uppercase;letter-spacing:.08em;opacity:.7">'.$e($p['text']).'</p>'
                : '<p style="margin:0 0 6px">'.$e($p['text']).'</p>';
        }
        if ($t['items']) {
            $box .= '<ul style="list-style:none;margin:6px 0;padding:0">'.implode('', array_map(fn ($i) => '<li style="margin:2px 0"><em>'.$e($i).'</em></li>', $t['items'])).'</ul>';
        }
        if ($t['price']) {
            $box .= '<p style="font-size:1.6rem;font-weight:700;margin:8px 0 0">'.$e(str_replace('/Starting', ' starting', $t['price'])).'</p>';
        }
        foreach ($t['links'] as $l) {
            $box .= '<p style="margin:10px 0 0"><a href="'.$e($l['href']).'" target="_blank">'.$e($l['text']).'</a></p>';
        }
        $out[] = $box.'</div>';
    }
}
$out[] = '</div>';
$content = implode("\n", $out);

// ------------------------------------------------ original design (site copy)
// Publish the website itself -- its HTML pages, css, js, fonts and only the
// images they reference (the site folder also holds ~310 MB of unused
// photos) -- so the Home page can show it exactly as it looks. PHP files are
// NOT copied (they'd execute under public/).
$siteRel = CompanyFiles::dir($companyId, 'store/sites/newera');
$siteAbs = public_path($siteRel);
$copyFile = function (string $rel) use ($siteDir, $siteAbs) {
    $src = $siteDir.'\\'.str_replace('/', '\\', $rel);
    if (! is_file($src)) {
        return false;
    }
    $dest = $siteAbs.'/'.$rel;
    if (! is_dir(dirname($dest))) {
        mkdir(dirname($dest), 0755, true);
    }

    return copy($src, $dest);
};
$siteFiles = 0;
foreach (glob($siteDir.'\\*.html') as $f) {
    $siteFiles += (int) $copyFile(basename($f));
}
foreach (['css', 'js', 'fonts'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($siteDir.'\\'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) !== 'php') {
            $siteFiles += (int) $copyFile(str_replace('\\', '/', substr($file->getPathname(), strlen($siteDir) + 1)));
        }
    }
}
$allText = implode("\n", array_map('file_get_contents', array_merge(
    glob($siteDir.'\\*.html'), glob($siteDir.'\\css\\*.css'), glob($siteDir.'\\js\\*.js')
)));
// Matches "images/x", "../images/x" (CSS) and 'images/x' (JS); a name that
// merely ends in "images/" (e.g. "myimages/") is not a site image.
preg_match_all('~(?<![A-Za-z0-9_-])(images/[^"\'()\s?#]+)~', $allText, $imgRefs);
foreach (array_unique($imgRefs[1]) as $rel) {
    $siteFiles += (int) $copyFile(rtrim($rel, '\\'));
}

$page = StorePage::updateOrCreate(
    ['company_id' => $companyId, 'slug' => 'home'],
    [
        'title' => 'Home',
        // Converted, editable version -- kept as the fallback when
        // "original design" is switched off in Pages.
        'content' => $content,
        'site_path' => $siteRel.'/index.html',
        'use_site_design' => true,
        'seo_title' => $title,
        'seo_description' => \Illuminate\Support\Str::limit(trim($title.'. '.$tagline), 250, ''),
        'published' => true,
    ]
);
echo "Original site published: {$siteFiles} files -> {$siteRel} (Home shows it: use_site_design = on)".PHP_EOL;

echo "Home page #{$page->id} for company {$companyId}: ".count($tiles).' tiles, '.count($copied).' images copied to '.$relDir.PHP_EOL;
foreach ($tiles as $t) {
    echo '  - '.$t['type'].': '.($t['title'] ?: '(untitled)').($t['type'] === 'photo' ? ' ['.basename((string) $t['img']).']' : '').PHP_EOL;
}
