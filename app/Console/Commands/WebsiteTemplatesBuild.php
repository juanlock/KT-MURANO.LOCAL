<?php

namespace App\Console\Commands;

use App\Services\SiteImporter;
use App\Support\WebsiteTemplates;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;

/*
 * Builds the ready-made website library (App\Support\WebsiteTemplates,
 * public/website-templates/, 2026-10-20) from a folder of website projects
 * served by a local web server (PHP sites are rendered, so the copies are
 * static): one crawl per project (SiteImporter::crawl), exact duplicates
 * skipped, images over 1920 px / 400 KB shrunk, a screenshot thumbnail
 * (Playwright), and templates.json. Run on a developer PC; the library is
 * committed and reaches production with the code.
 *
 *   php artisan murano:website-templates "D:\WEBSITES-LOCAL\KT-DEVELOP.LOCAL\PROJECTS_HTML" --base-url=http://kt-develop.local/PROJECTS_HTML
 *   ... --only=arq --only=city   (rebuild some)   --thumbs-only
 */
class WebsiteTemplatesBuild extends Command
{
    protected $signature = 'murano:website-templates {source : Folder with one sub-folder per website}
        {--base-url= : Address where the source folder is served (e.g. http://kt-develop.local/PROJECTS_HTML)}
        {--only=* : Only these folders} {--thumbs-only : Only redo the screenshots}';

    protected $description = 'Build the ready-made website library (public/website-templates) from website project folders';

    /** Display names (folder => name); versions of the same business get v1 / v2. */
    private const NAMES = [
        'arq' => 'Arq. Sammy Waldman', 'arqcm' => 'ARQ CM', 'arquitectos-eb' => 'Arquitectos EB', 'artedigital' => 'Arte Digital',
        'cambio' => 'Cambio', 'ciruplastic' => 'Ciruplastic', 'city' => 'City', 'cleanservices' => 'Clean Services',
        'creandoexcelencia' => 'Creando Excelencia', 'crisp' => 'Crisp', 'dpiel' => 'D\'Piel', 'evita' => 'Evita',
        'fastpaclogistics' => 'Fastpac Logistics', 'guimaclandscape' => 'Guimac Landscape', 'guimactransporte' => 'Guimac Transporte',
        'kcoltech1' => 'KcolTech v1', 'kcoltech2' => 'KcolTech v2', 'kunturfly1' => 'Kunturfly v1', 'kunturfly2' => 'Kunturfly v2',
        'legoalsystem' => 'Legoal System', 'limosinas' => 'Limosinas', 'limousina' => 'Limousina',
        'mbmjanitorialservices' => 'MBM Janitorial Services', 'miguelromerolp' => 'Miguel Romero LP', 'mplatanitos' => 'M Platanitos',
        'rubagardenlandscape' => 'Ruba Garden Landscape', 'smart' => 'Smart', 'tonykyntoflow' => 'Tony Kynto Flow',
        'transportescautivo' => 'Transportes Cautivo', 'turismocautivo' => 'Turismo Cautivo v1', 'turismocautivo2' => 'Turismo Cautivo v2',
        'za-form' => 'ZA Form', 'zonaurea' => 'Zona Áurea',
    ];

    /** Folders left out, with the reason. */
    private const SKIP = [
        'transportescautivo' => 'a Flash (.swf) website -- browsers no longer play Flash',
    ];

    public function handle(): int
    {
        $source = rtrim((string) $this->argument('source'), '\\/');
        $base = rtrim((string) $this->option('base-url'), '/');
        if (! is_dir($source) || $base === '') {
            $this->error('Give the source folder and --base-url.');

            return self::FAILURE;
        }
        @set_time_limit(0);
        $lib = public_path(WebsiteTemplates::DIR);
        File::ensureDirectoryExists($lib);
        $manifest = collect(is_file(WebsiteTemplates::manifestPath()) ? json_decode(file_get_contents(WebsiteTemplates::manifestPath()), true) : [])->keyBy('key');
        $only = array_map('strtolower', (array) $this->option('only'));
        $folders = collect(File::directories($source))->map(fn ($d) => basename($d))->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();

        $hashes = [];
        foreach ($folders as $folder) {
            $key = Str::slug(strtolower($folder));
            if ($only && ! in_array(strtolower($folder), $only, true) && ! in_array($key, $only, true)) {
                continue;
            }
            if (isset(self::SKIP[$key])) {
                $this->warn("$folder: skipped -- ".self::SKIP[$key]);
                $manifest->forget($key);
                File::deleteDirectory($lib.'/'.$key);
                continue;
            }
            // Exact duplicates (same files, same contents) are skipped.
            $hash = $this->folderHash($source.DIRECTORY_SEPARATOR.$folder);
            if (isset($hashes[$hash])) {
                $this->warn("$folder: same files as {$hashes[$hash]} -- skipped");
                $manifest->forget($key);
                File::deleteDirectory($lib.'/'.$key);
                continue;
            }
            $hashes[$hash] = $folder;
            if ($this->option('thumbs-only')) {
                continue;
            }

            $this->info("$folder ...");
            $dir = $lib.'/'.$key;
            File::deleteDirectory($dir);
            try {
                $r = SiteImporter::crawl($base.'/'.rawurlencode($folder).'/', $dir, 40);
            } catch (\Throwable $e) {
                $this->error("  crawl failed: ".$e->getMessage());
                continue;
            }
            if ($r['pages'] < 1 || ! is_file($dir.'/'.$r['entry'])) {
                $this->error('  no page could be read -- skipped');
                File::deleteDirectory($dir);
                $manifest->forget($key);
                continue;
            }
            $filled = $this->fillScriptFolders($source.DIRECTORY_SEPARATOR.$folder, $dir);
            $saved = $this->optimizeImages($dir);
            $html = (string) file_get_contents($dir.'/'.$r['entry']);
            $title = trim(html_entity_decode(strip_tags(preg_match('/<title>(.*?)<\/title>/is', $html, $m) ? $m[1] : '')));
            $files = iterator_count(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)));
            $mb = round($this->dirSize($dir) / 1048576, 1);
            $manifest[$key] = [
                'key' => $key, 'name' => self::NAMES[$key] ?? Str::headline($folder), 'title' => mb_substr($title, 0, 120),
                'entry' => $r['entry'], 'pages' => $r['pages'], 'files' => $files, 'mb' => $mb, 'source' => $folder,
            ];
            $this->line("  {$r['pages']} page(s), {$r['assets']} file(s)".($filled ? " + {$filled} script-folder file(s)" : '').", {$mb} MB".($saved ? ', images -'.round($saved / 1048576, 1).' MB' : ''));
        }

        $list = $manifest->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        file_put_contents(WebsiteTemplates::manifestPath(), json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->thumbnails(array_values(array_filter($list, fn ($t) => ! $only || in_array($t['key'], $only, true) || in_array(strtolower($t['source']), $only, true))));
        $this->info(count($list).' website(s) in the library, '.round($this->dirSize($lib) / 1048576, 1).' MB.');

        return self::SUCCESS;
    }

    /** Screenshots (Playwright, 1280x800) shrunk to 800 px wide JPGs. */
    private function thumbnails(array $list): void
    {
        if (! $list) {
            return;
        }
        $jobs = array_map(fn ($t) => [
            'url' => url(WebsiteTemplates::DIR.'/'.$t['key'].'/'.$t['entry']),
            'out' => WebsiteTemplates::absPath($t['key']).'/'.WebsiteTemplates::THUMB,
        ], $list);
        $jobFile = storage_path('app/website-template-thumbs.json');
        file_put_contents($jobFile, json_encode($jobs));
        $res = Process::timeout(1800)->run(['node', base_path('database/scripts/website_template_thumbs.cjs'), $jobFile]);
        @unlink($jobFile);
        if (! $res->successful()) {
            $this->warn('Screenshots failed: '.trim($res->errorOutput() ?: $res->output()));

            return;
        }
        foreach ($jobs as $j) {
            if (is_file($j['out'])) {
                Image::make($j['out'])->resize(800, null, fn ($c) => $c->aspectRatio())->encode('jpg', 78)->save($j['out']);
            }
        }
        $this->line('Screenshots: '.count($jobs));
    }

    /**
     * Plugins load files by names their scripts put together at run time
     * (yoxviewPath + "yoxview-nojquery.js") -- no crawl can see those. Every
     * folder of the copy that holds a script gets the rest of that folder from
     * the source (sub-folders too), never .php / server files, nothing over 2 MB.
     */
    private function fillScriptFolders(string $src, string $dir): int
    {
        $added = 0;
        $skip = ['php', 'phtml', 'cgi', 'pl', 'py', 'sh', 'htaccess', 'zip', 'rar', 'psd', 'bak', 'sql'];
        $scriptDirs = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if (strtolower($f->getExtension()) === 'js' && $f->getPath() !== $dir) {
                $scriptDirs[substr($f->getPath(), strlen($dir))] = true;
            }
        }
        foreach (array_keys($scriptDirs) as $rel) {
            $from = $src.$rel;
            if (! is_dir($from)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS)) as $f) {
                $to = $dir.$rel.substr($f->getPathname(), strlen($from));
                if (is_file($to) || $f->getSize() > 2 * 1048576 || in_array(strtolower($f->getExtension()), $skip, true)) {
                    continue;
                }
                File::ensureDirectoryExists(dirname($to));
                copy($f->getPathname(), $to);
                $added++;
            }
        }

        return $added;
    }

    /** Images wider than 1920 px or heavier than 400 KB: resized / recompressed when that makes them smaller. */
    private function optimizeImages(string $dir): int
    {
        $saved = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $ext = strtolower($f->getExtension());
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || $f->getSize() < 400 * 1024) {
                continue;
            }
            try {
                $before = $f->getSize();
                $img = Image::make($f->getPathname());
                if ($img->width() > 1920) {
                    $img->resize(1920, null, fn ($c) => $c->aspectRatio());
                }
                $data = (string) $img->encode($ext === 'jpeg' ? 'jpg' : $ext, 80);
                if (strlen($data) < $before) {
                    file_put_contents($f->getPathname(), $data);
                    $saved += $before - strlen($data);
                }
                $img->destroy();
            } catch (\Throwable $e) {
                // An image Intervention can't read stays as it is.
            }
        }

        return $saved;
    }

    private function folderHash(string $dir): string
    {
        $parts = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $parts[] = str_replace('\\', '/', substr($f->getPathname(), strlen($dir))).':'.md5_file($f->getPathname());
        }
        sort($parts);

        return md5(implode("\n", $parts));
    }

    private function dirSize(string $dir): int
    {
        $n = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $n += $f->getSize();
        }

        return $n;
    }
}
