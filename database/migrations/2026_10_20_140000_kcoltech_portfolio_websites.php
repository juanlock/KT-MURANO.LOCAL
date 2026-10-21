<?php

use App\Support\CompanyFiles;
use App\Support\WebsiteTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * KcolTech's portfolio (user's request, 2026-10-20): one project per ready-made
 * website (App\Support\WebsiteTemplates -- "KcolTech v1", "KcolTech v2", ...)
 * in its "Web Design" category, the screenshot as cover and a "Visit website"
 * link to the copy. Added only: nothing of KcolTech's portfolio is replaced.
 * By name, not by id (company "KcolTech", category "Web Design", project slug
 * website-{key}); safe to run twice -- a website already there is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('portfolio_projects') || ! Schema::hasColumn('portfolio_projects', 'website_url')) {
            return;
        }
        $cid = DB::table('companies')->whereRaw('LOWER(name) = ?', ['kcoltech'])->whereNull('deleted_at')->value('id');
        $templates = WebsiteTemplates::all();
        if (! $cid || ! $templates) {
            return;
        }
        $now = now();
        $cat = DB::table('portfolio_categories')->where('company_id', $cid)->whereRaw('LOWER(name) = ?', ['web design'])->value('id')
            ?? DB::table('portfolio_categories')->insertGetId([
                'company_id' => $cid, 'name' => 'Web Design', 'slug' => 'web-design',
                'sort_order' => (int) DB::table('portfolio_categories')->where('company_id', $cid)->max('sort_order') + 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        $sort = (int) DB::table('portfolio_projects')->where('company_id', $cid)->max('sort_order');
        $dir = CompanyFiles::dir($cid, 'portfolio').'/websites';
        if (! is_dir(public_path($dir))) {
            mkdir(public_path($dir), 0775, true);
        }

        foreach ($templates as $t) {
            $slug = 'website-'.$t['key'];
            if (DB::table('portfolio_projects')->where('company_id', $cid)->where('slug', $slug)->exists()) {
                continue;
            }
            $cover = null;
            $thumb = WebsiteTemplates::absPath($t['key']).'/'.WebsiteTemplates::THUMB;
            if (is_file($thumb) && @copy($thumb, public_path($dir.'/'.$t['key'].'.jpg'))) {
                $cover = $dir.'/'.$t['key'].'.jpg';
            }
            $client = trim(preg_replace('/\s+v\d+$/i', '', $t['name']));
            DB::table('portfolio_projects')->insert([
                'company_id' => $cid, 'title' => $t['name'], 'slug' => $slug, 'type' => 'image', 'cover' => $cover,
                'description' => (trim($t['title'] ?? '') !== '' ? trim($t['title'], " \t\n\r:.")."\n\n" : '').'Website designed and built by KcolTech ('.$t['pages'].' '.($t['pages'] == 1 ? 'page' : 'pages').').',
                'client' => $client, 'location' => null, 'project_date' => null, 'video_url' => null,
                'website_url' => '/'.WebsiteTemplates::DIR.'/'.$t['key'].'/'.$t['entry'],
                'categories' => json_encode([(int) $cat]), 'featured' => 0, 'published' => 1, 'sort_order' => ++$sort,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Portfolio data: not removed automatically.
    }
};
