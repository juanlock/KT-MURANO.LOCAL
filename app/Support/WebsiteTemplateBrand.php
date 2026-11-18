<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A ready-made website becomes the company's own when it is used (user, 2026-11-17: "al importar no reemplaza con el
 * logo de la compañía"): in the company's copy (files/company_{id}/website/sites/{name}/), never in the library,
 *   - the logo image (an <img> whose file / class / id says "logo", or the first one inside a navbar-brand / logo
 *     element; social-network logos are left alone) and the favicon -> the company's logo, copied to _brand/;
 *   - <title> -> the company's name, and the template's own brand name (from its manifest title / name) in the
 *     visible text -> the company's name;
 *   - mailto: links and e-mail addresses in the text -> the company's e-mail; tel: links and their text -> its phone.
 * Everything else (pictures, texts, other phone numbers) is edited afterwards in Website -> Pages.
 */
class WebsiteTemplateBrand
{
    private const SOCIAL = '/youtube|facebook|twitter|instagram|whatsapp|linkedin|tiktok|pinterest|google|apple|paypal|visa|mastercard/i';

    /** Generic words a template's title can hold that are not a brand to replace. */
    private const GENERIC = ['home', 'flat theme', 'remix', 'cambio', 'city', 'smart', 'landing page', 'template'];

    /** @return array{logo: int, name: int, email: int, phone: int, files: int} */
    public static function apply(int $companyId, string $abs, array $tpl): array
    {
        $co = self::company($companyId);
        $logoRel = self::copyLogo($co['logo'], $abs);
        $names = self::brandNames($tpl);
        $count = ['logo' => 0, 'name' => 0, 'email' => 0, 'phone' => 0, 'files' => 0];

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (! $f->isFile() || ! preg_match('/\.html?$/i', $f->getFilename())) {
                continue;
            }
            $html = (string) file_get_contents($f->getPathname());
            $depth = substr_count(str_replace('\\', '/', substr($f->getPath(), strlen($abs))), '/');
            $up = str_repeat('../', $depth);
            $new = self::html($html, $co, $logoRel ? $up.$logoRel : null, $names, $count);
            if ($new !== $html) {
                file_put_contents($f->getPathname(), $new);
                $count['files']++;
            }
        }

        return $count;
    }

    private static function company(int $companyId): array
    {
        $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first();
        $c = DB::table('companies')->where('id', $companyId)->first();
        $logo = null;
        if (Schema::hasTable('website_settings')) {
            $logo = DB::table('website_settings')->where('company_id', $companyId)->value('logo_path');
        }
        $logo = $logo ?: ($s->logo ?? null) ?: ($c->logo ?? null);

        return [
            'name' => trim((string) (($s->CompanyName ?? null) ?: ($c->name ?? ''))),
            'email' => trim((string) (($s->email ?? null) ?: ($c->email ?? ''))),
            'phone' => trim((string) (($s->CompanyPhone ?? null) ?: ($c->phone ?? ''))),
            'logo' => $logo,
        ];
    }

    /** The company's logo copied into the site (_brand/logo.ext); its path relative to the site root, or null. */
    private static function copyLogo(?string $logo, string $abs): ?string
    {
        if (! $logo) {
            return null;
        }
        $src = public_path(ltrim(preg_replace('#^/?(public/)?#', '', $logo), '/'));
        if (! is_file($src)) {
            return null;
        }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION) ?: 'png');
        if (! is_dir($abs.'/_brand')) {
            mkdir($abs.'/_brand', 0775, true);
        }
        copy($src, $abs.'/_brand/logo.'.$ext);

        return '_brand/logo.'.$ext;
    }

    /** The template's own brand names, longest first: its title's parts and its name (without "v1" / "v2"). */
    private static function brandNames(array $tpl): array
    {
        $title = trim(preg_replace('/[:.]{2,}/', ' ', (string) ($tpl['title'] ?? '')));
        $out = [];
        foreach (array_merge([$title], preg_split('/\s+(?:-|\||\|\||::|–)\s+/', $title)) as $p) {
            $p = trim($p, " \t:.|-");
            if (mb_strlen($p) >= 6 && ! self::generic($p)) {
                $out[] = $p;
            }
        }
        $name = trim(preg_replace('/\s+v\d+$/i', '', (string) ($tpl['name'] ?? '')));
        if (mb_strlen($name) >= 6 && ! self::generic($name) && stripos($title, $name) !== false) {
            $out[] = $name;
        }
        $out = array_values(array_unique($out));
        usort($out, fn ($a, $b) => strlen($b) <=> strlen($a));

        return $out;
    }

    private static function generic(string $s): bool
    {
        $l = strtolower($s);
        foreach (self::GENERIC as $g) {
            if ($l === $g || str_contains($l, 'template')) {
                return true;
            }
        }

        return false;
    }

    private static function html(string $html, array $co, ?string $logo, array $names, array &$count): string
    {
        if ($logo) {
            // An <img> that says "logo" (file name, class, id or alt) -- not a social network's.
            $html = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($logo, &$count) {
                $tag = $m[0];
                if (! preg_match('/(src|class|id|alt)\s*=\s*["\'][^"\']*logo/i', $tag) || preg_match(self::SOCIAL, $tag)) {
                    return $tag;
                }
                $count['logo']++;

                return self::setLogo($tag, $logo);
            }, $html);
            // The first <img> right inside a navbar-brand / logo / brand element.
            $html = preg_replace_callback('/(<[a-z]+\b[^>]*class\s*=\s*["\'][^"\']*(?:navbar-brand|logo|brand)[^"\']*["\'][^>]*>\s*(?:<a\b[^>]*>\s*)?)(<img\b[^>]*>)/i',
                function ($m) use ($logo, &$count) {
                    if (str_contains($m[2], $logo) || preg_match(self::SOCIAL, $m[2])) {
                        return $m[0];
                    }
                    $count['logo']++;

                    return $m[1].self::setLogo($m[2], $logo);
                }, $html);
            // A logo drawn by the CSS (an empty #logo / .logo element with a background picture, e.g. Arte Digital):
            // the background off and the company's logo put inside it (its link, when it has one). Once a page.
            if (! str_contains($html, 'src="'.$logo.'"')) {
                $html = preg_replace_callback('/<(div|span|a|h1|li)\b([^>]*\b(?:id|class)\s*=\s*["\'](?:[^"\']*\s)?logo(?:\s[^"\']*)?["\'][^>]*)>((?:\s*<a\b[^>]*>)?)(\s*)(?=<\/)/i',
                    function ($m) use ($logo, &$count) {
                        $count['logo']++;
                        $attrs = preg_match('/\sstyle\s*=\s*(["\'])/i', $m[2])
                            ? preg_replace('/\sstyle\s*=\s*(["\'])/i', ' style=$1background:none;', $m[2], 1)
                            : $m[2].' style="background:none"';

                        return '<'.$m[1].$attrs.'>'.$m[3].'<img src="'.$logo.'" alt="logo" style="max-height:60px;width:auto;display:block">'.$m[4];
                    }, $html, 1);
            }
            // Favicon.
            $html = preg_replace('/(<link\b[^>]*rel\s*=\s*["\'][^"\']*icon[^"\']*["\'][^>]*href\s*=\s*["\'])[^"\']*(["\'])/i', '${1}'.$logo.'${2}', $html);
            $html = preg_replace('/(<link\b[^>]*href\s*=\s*["\'])[^"\']*(["\'][^>]*rel\s*=\s*["\'][^"\']*icon[^"\']*["\'])/i', '${1}'.$logo.'${2}', $html);
        }

        $name = htmlspecialchars($co['name'], ENT_QUOTES);
        if ($name !== '') {
            $html = preg_replace('/<title>.*?<\/title>/is', '<title>'.$name.'</title>', $html, 1);
        }

        // tel: links (and the number shown in them) -> the company's phone.
        if ($co['phone'] !== '') {
            $tel = preg_replace('/[^\d+]/', '', $co['phone']);
            $html = preg_replace_callback('/(<a\b[^>]*href\s*=\s*["\'])tel:[^"\']*(["\'][^>]*>)(.*?)(<\/a>)/is', function ($m) use ($tel, $co, &$count) {
                $count['phone']++;
                $inner = preg_match('/\d{3}/', strip_tags($m[3])) ? preg_replace('/[+(]?\d[\d\s().\-]{5,}\d/', htmlspecialchars($co['phone']), $m[3]) : $m[3];

                return $m[1].'tel:'.$tel.$m[2].$inner.$m[4];
            }, $html);
        }
        // mailto: links -> the company's e-mail.
        if ($co['email'] !== '') {
            $html = preg_replace_callback('/mailto:[^"\'?\s]+/i', function () use ($co, &$count) {
                $count['email']++;

                return 'mailto:'.$co['email'];
            }, $html);
        }

        // Visible text only (between tags, outside <script> / <style>): brand name and e-mail addresses.
        return preg_replace_callback('/(<script\b.*?<\/script>|<style\b.*?<\/style>)|>([^<]+)</is', function ($m) use ($names, $co, $name, &$count) {
            if (! empty($m[1])) {
                return $m[0];
            }
            $t = $m[2];
            if ($name !== '') {
                foreach ($names as $n) {
                    $t = str_ireplace($n, $name, $t, $c);
                    $count['name'] += $c;
                }
            }
            if ($co['email'] !== '') {
                $t = preg_replace_callback('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(\.[A-Za-z0-9\-]+)+/', function () use ($co, &$count) {
                    $count['email']++;

                    return htmlspecialchars($co['email']);
                }, $t);
            }

            return '>'.$t.'<';
        }, $html);
    }

    private static function setLogo(string $tag, string $logo): string
    {
        $tag = preg_replace('/\s(srcset|data-src|data-srcset)\s*=\s*(["\']).*?\2/i', '', $tag);
        $tag = preg_replace('/\ssrc\s*=\s*(["\']).*?\1/i', ' src="'.$logo.'"', $tag, 1, $n);
        if (! $n) {
            $tag = preg_replace('/^<img\b/i', '<img src="'.$logo.'"', $tag);
        }
        // Keep the logo's own proportions inside the template's slot.
        if (! preg_match('/\sstyle\s*=/i', $tag)) {
            $tag = preg_replace('/\s(width|height)\s*=\s*(["\']).*?\2/i', '', $tag);
            $tag = preg_replace('/^<img\b/i', '<img style="max-height:70px;width:auto"', $tag);
        }

        return $tag;
    }
}
