<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Imported websites belong to the Website module (user's rule, 2026-10-07):
 * files/company_{id}/store/sites/{name}/ -> files/company_{id}/website/sites/{name}/.
 * They were imported before Online Store and Website Management were split.
 *
 * Moves the folders (on other machines git already brings them moved, so
 * nothing is left to move) and rewrites the paths kept in the database:
 * store_settings.front_page_path, store_pages.site_path / content.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->move('store/sites', 'website/sites');
    }

    public function down(): void
    {
        $this->move('website/sites', 'store/sites');
    }

    private function move(string $from, string $to): void
    {
        foreach (glob(public_path('files/company_*/'.$from.'/*'), GLOB_ONLYDIR) ?: [] as $src) {
            $dest = dirname($src, 3).'/'.$to.'/'.basename($src);
            if (is_dir($dest)) {
                continue; // never overwrite a site already there
            }
            File::ensureDirectoryExists(dirname($dest));
            File::moveDirectory($src, $dest);
        }
        foreach (glob(public_path('files/company_*/'.$from), GLOB_ONLYDIR) ?: [] as $dir) {
            if (! (new FilesystemIterator($dir))->valid()) {
                rmdir($dir);
            }
        }

        $a = '/'.$from.'/';
        $b = '/'.$to.'/';
        DB::table('store_settings')->where('front_page_path', 'like', 'files/company_%'.$a.'%')
            ->update(['front_page_path' => DB::raw('REPLACE(front_page_path, '.DB::getPdo()->quote($a).', '.DB::getPdo()->quote($b).')')]);
        DB::table('store_pages')->where('site_path', 'like', 'files/company_%'.$a.'%')
            ->update(['site_path' => DB::raw('REPLACE(site_path, '.DB::getPdo()->quote($a).', '.DB::getPdo()->quote($b).')')]);
        foreach (DB::table('store_pages')->where('content', 'like', '%files/company_%'.$a.'%')->get(['id', 'content']) as $p) {
            DB::table('store_pages')->where('id', $p->id)->update([
                'content' => preg_replace('#(files/company_\d+)'.preg_quote($a, '#').'#', '$1'.$b, $p->content),
            ]);
        }
    }
};
