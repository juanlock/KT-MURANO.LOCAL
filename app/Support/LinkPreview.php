<?php

namespace App\Support;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The preview of a pasted link (user, 2026-11-22): platform (YouTube, Vimeo, TikTok, Google Drive...), title,
 * description and picture. YouTube / Vimeo / TikTok through their public oEmbed, any other page through its
 * og: / <title> tags. Only public http(s) addresses (no local network), 3 redirects at most, cached for a day.
 * Never throws: an address that can't be read gives just the platform and the address.
 */
class LinkPreview
{
    private const PLATFORMS = [
        'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'vimeo.com' => 'Vimeo', 'tiktok.com' => 'TikTok',
        'facebook.com' => 'Facebook', 'fb.watch' => 'Facebook', 'instagram.com' => 'Instagram', 'x.com' => 'X', 'twitter.com' => 'X',
        'linkedin.com' => 'LinkedIn', 'drive.google.com' => 'Google Drive', 'docs.google.com' => 'Google Docs',
        'onedrive.live.com' => 'OneDrive', '1drv.ms' => 'OneDrive', 'sharepoint.com' => 'SharePoint', 'dropbox.com' => 'Dropbox',
        'zoom.us' => 'Zoom', 'meet.google.com' => 'Google Meet', 'github.com' => 'GitHub', 'figma.com' => 'Figma',
        'canva.com' => 'Canva', 'loom.com' => 'Loom', 'wa.me' => 'WhatsApp', 'maps.google.com' => 'Google Maps',
    ];

    private const OEMBED = [
        'YouTube' => 'https://www.youtube.com/oembed?format=json&url=',
        'Vimeo' => 'https://vimeo.com/api/oembed.json?url=',
        'TikTok' => 'https://www.tiktok.com/oembed?url=',
    ];

    public static function platform(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::PLATFORMS as $domain => $name) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return $name;
            }
        }

        return null;
    }

    /** {url, platform, site, title, description, thumbnail, kind: video|page} */
    public static function get(string $url): array
    {
        return Cache::remember('linkpreview:v4:'.sha1($url), 86400, function () use ($url) {
            $platform = self::platform($url);
            $out = ['url' => $url, 'platform' => $platform, 'site' => $platform ?: (string) parse_url($url, PHP_URL_HOST),
                'title' => null, 'description' => null, 'author' => null, 'thumbnail' => null,
                'kind' => in_array($platform, ['YouTube', 'Vimeo', 'TikTok', 'Loom'], true) ? 'video' : 'page'];
            try {
                // The page first: a short link (vt.tiktok.com, youtu.be, bit.ly...) is followed to its real address,
                // which is what the platforms' oEmbed understands (2026-11-22: a TikTok short link showed no picture).
                [$final, $page] = self::fetchFollow($url);
                $finalPlatform = $final ? self::platform($final) : null;
                if ($finalPlatform && ! $platform) {
                    $platform = $finalPlatform;
                    $out['platform'] = $out['site'] = $platform;
                    $out['kind'] = in_array($platform, ['YouTube', 'Vimeo', 'TikTok', 'Loom'], true) ? 'video' : 'page';
                }
                $embedUrl = $final ?: $url;
                if ($platform === 'TikTok') {
                    $embedUrl = preg_replace('~\?.*$~', '', $embedUrl);
                }
                if ($platform && isset(self::OEMBED[$platform])) {
                    $r = self::fetch(self::OEMBED[$platform].rawurlencode($embedUrl));
                    if ($r && $r->ok() && is_array($j = $r->json())) {
                        $out['title'] = $j['title'] ?? null;
                        $out['thumbnail'] = $j['thumbnail_url'] ?? null;
                        $out['description'] = $j['description'] ?? null;
                        $out['author'] = $j['author_name'] ?? null;
                    }
                }
                // The page itself: its description (YouTube's oEmbed has none) and anything still missing.
                $r = $page;
                if ($r && $r->ok() && str_contains(strtolower((string) $r->header('Content-Type')), 'html')) {
                    $html = substr($r->body(), 0, 600000);
                    $meta = fn (string $p) => self::meta($html, $p);
                    $out['title'] = $out['title'] ?: ($meta('og:title') ?: (preg_match('~<title[^>]*>(.*?)</title>~si', $html, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES)) : null));
                    $desc = $meta('og:description') ?: $meta('description');
                    if ($desc && ! $out['description']) {
                        $out['description'] = $desc;
                    }
                    $out['thumbnail'] = $out['thumbnail'] ?: $meta('og:image');
                    // TikTok often sends no picture by oEmbed: its page data has the cover, the text and the author.
                    if ($platform === 'TikTok') {
                        $js = fn (string $k) => preg_match('~"'.$k.'":"((?:[^"\\\\]|\\\\.)*)"~', $html, $mm) ? (json_decode('"'.$mm[1].'"') ?: null) : null;
                        $out['thumbnail'] = $out['thumbnail'] ?: ($js('cover') ?: $js('originCover'));
                        if (! $out['title'] || str_starts_with((string) $out['title'], 'TikTok - ')) {
                            $out['title'] = $js('desc') ?: $out['title'];
                        }
                        $out['author'] = $out['author'] ?: $js('nickname');
                    }
                    if (! $platform && ($site = $meta('og:site_name'))) {
                        $out['site'] = $site;
                    }
                }
            } catch (\Throwable $e) {
                // An address that can't be read keeps the platform and the address.
            }
            foreach (['title', 'description'] as $k) {
                if ($out[$k]) {
                    $out[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', $out[$k])), 0, $k === 'title' ? 250 : 600);
                }
            }
            if ($out['thumbnail'] && ! preg_match('~^https?://~i', $out['thumbnail'])) {
                $out['thumbnail'] = (string) UriResolver::resolve(new Uri($url), new Uri($out['thumbnail']));
            }

            return $out;
        });
    }

    /** A picture's bytes (public addresses only, up to 5 MB, image/* only), or null. */
    public static function image(?string $url): ?array
    {
        if (! $url) {
            return null;
        }
        try {
            [, $r] = self::fetchFollow($url);
            $type = strtolower((string) optional($r)->header('Content-Type'));
            if ($r && $r->ok() && str_starts_with($type, 'image/') && strlen($r->body()) <= 5 * 1024 * 1024) {
                return ['bytes' => $r->body(), 'ext' => str_contains($type, 'png') ? 'png' : (str_contains($type, 'webp') ? 'webp' : 'jpg')];
            }
        } catch (\Throwable $e) {
            // no picture
        }

        return null;
    }

    private static function meta(string $html, string $prop): ?string
    {
        $p = preg_quote($prop, '~');
        if (preg_match('~<meta[^>]+(?:property|name)=["\']'.$p.'["\'][^>]*content=["\']([^"\']*)~i', $html, $m)
            || preg_match('~<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']'.$p.'["\']~i', $html, $m)) {
            return trim(html_entity_decode($m[1], ENT_QUOTES)) ?: null;
        }

        return null;
    }

    /** GET with the public-address check on every hop. */
    private static function fetch(string $url)
    {
        return self::fetchFollow($url)[1];
    }

    /** [final address, response] (null, null when the address can't be used). */
    private static function fetchFollow(string $url): array
    {
        for ($hop = 0; $hop < 4; $hop++) {
            $host = parse_url($url, PHP_URL_HOST);
            $ips = $host ? (gethostbynamel($host) ?: []) : [];
            $public = $ips && collect($ips)->every(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
            if (! $public || ! in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)) {
                return [null, null];
            }
            $res = Http::withOptions(['allow_redirects' => false])->timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124 Safari/537.36', 'Accept-Language' => 'es,en;q=0.8'])->get($url);
            if ($res->redirect() && $res->header('Location')) {
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($res->header('Location')));

                continue;
            }

            return [$url, $res];
        }

        return [null, null];
    }
}
