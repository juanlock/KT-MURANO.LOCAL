<?php

namespace App\Http\Controllers\Api\Portfolio;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\CompanyFiles;
use App\Support\ModuleFlags;
use App\Support\PortfolioThemes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portfolio module -- back office (permission portfolio_manage; module
 * "portfolio"). Everything belongs to the acting company (an Admin: their
 * own; a SuperAdmin: the company picked in the header, not "all").
 *
 *  GET    portfolio                          site + categories + projects + unread messages
 *  PUT    portfolio/site                     texts / theme / contact / social / published
 *  POST   portfolio/site/image?kind=logo|about   (file "image"); DELETE removes it
 *  POST   portfolio/categories {name}; PUT/DELETE portfolio/categories/{id}; POST portfolio/categories/reorder {ids}
 *  POST   portfolio/projects; PUT/DELETE portfolio/projects/{id}; POST portfolio/projects/reorder {ids}
 *  POST   portfolio/projects/{id}/cover (file "image")
 *  POST   portfolio/projects/{id}/media (files "images[]"); DELETE portfolio/media/{id}; POST portfolio/projects/{id}/media/reorder {ids}
 *  GET    portfolio/messages; PUT portfolio/messages/{id} {is_read}; DELETE portfolio/messages/{id}
 * Files: public/files/company_{id}/portfolio/ (public: they are on the website).
 */
class PortfolioController extends Controller
{
    use ScopesToCompany;

    private const MODULE = 'portfolio';

    private function cid(Request $request): int
    {
        abort_unless(HelpdeskAccess::can($request->user('api'), 'portfolio_manage'), 403, 'You do not have permission for Portfolio.');
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company in the header first.');
        ModuleFlags::abortUnlessEnabled($cid, 'portfolio');

        return $cid;
    }

    /** The company's site, created on first use (slug from the company name). */
    private function site(int $cid): object
    {
        $site = DB::table('portfolio_sites')->where('company_id', $cid)->first();
        if (! $site) {
            $name = (string) (DB::table('companies')->where('id', $cid)->value('name') ?: 'Portfolio');
            $base = Str::slug($name) ?: 'portfolio-'.$cid;
            $slug = $base;
            for ($i = 2; DB::table('portfolio_sites')->where('slug', $slug)->exists(); $i++) {
                $slug = $base.'-'.$i;
            }
            DB::table('portfolio_sites')->insert([
                'company_id' => $cid, 'slug' => $slug, 'name' => $name, 'theme' => 'dark', 'published' => false,
                'about_title' => 'About us', 'contact_subtitle' => 'Get in touch with us', 'skills' => json_encode([]), 'social' => json_encode([]),
                'pages' => json_encode(['about' => true, 'contact' => true]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            // Home starts like the example: the works gallery.
            if (! DB::table('portfolio_sections')->where('company_id', $cid)->exists()) {
                DB::table('portfolio_sections')->insert([
                    'company_id' => $cid, 'type' => 'gallery', 'settings' => json_encode(['featured_only' => true, 'show_all' => false]),
                    'visible' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $site = DB::table('portfolio_sites')->where('company_id', $cid)->first();
        }

        return $site;
    }

    private function url(?string $path): ?string
    {
        return $path ? '/'.ltrim($path, '/') : null;
    }

    private function siteOut(object $s): array
    {
        $a = (array) $s;
        $a['skills'] = json_decode((string) $s->skills, true) ?: [];
        $a['social'] = (object) (json_decode((string) $s->social, true) ?: []);
        $a['pages'] = array_merge(['about' => true, 'contact' => true], json_decode((string) ($s->pages ?? ''), true) ?: []);
        $a['logo_url'] = $this->url($s->logo);
        $a['about_image_url'] = $this->url($s->about_image);
        $a['bg_image_url'] = $this->url($s->bg_image ?? null);
        $a['public_url'] = url('/portfolio/'.$s->slug);
        // Settings -> Theme / Menu (the default menu until the company edits it).
        $a['template'] = PortfolioThemes::key($s->template ?? null);
        $a['menu_on_home'] = (bool) ($s->menu_on_home ?? false);
        $menu = json_decode((string) ($s->menu ?? ''), true);
        $a['menu_custom'] = is_array($menu);
        $a['default_menu'] = PortfolioThemes::defaultMenu($a['pages'], $s->about_title);
        $a['menu'] = is_array($menu) ? $menu : $a['default_menu'];

        return $a;
    }

    private function projectsOut(int $cid)
    {
        $media = DB::table('portfolio_media')->where('company_id', $cid)->orderBy('sort_order')->orderBy('id')->get()->groupBy('project_id');

        return DB::table('portfolio_projects')->where('company_id', $cid)->orderBy('sort_order')->orderBy('id')->get()
            ->map(function ($p) use ($media) {
                $a = (array) $p;
                $a['categories'] = array_map('intval', json_decode((string) $p->categories, true) ?: []);
                $a['featured'] = (bool) $p->featured;
                $a['published'] = (bool) $p->published;
                $a['cover_url'] = $this->url($p->cover);
                $a['media'] = ($media[$p->id] ?? collect())->map(fn ($m) => ['id' => $m->id, 'url' => $this->url($m->path)])->values();

                return $a;
            });
    }

    public function index(Request $request)
    {
        $cid = $this->cid($request);
        $site = $this->site($cid);

        return response()->json([
            'site' => $this->siteOut($site),
            'themes' => PortfolioThemes::options(),
            'categories' => DB::table('portfolio_categories')->where('company_id', $cid)->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'slug', 'sort_order']),
            'projects' => $this->projectsOut($cid),
            'sections' => $this->sectionsOut($cid),
            'unread' => DB::table('portfolio_messages')->where('company_id', $cid)->where('is_read', false)->count(),
        ]);
    }

    /* ------------------------------------------------------------ site */

    public function updateSite(Request $request)
    {
        $cid = $this->cid($request);
        $site = $this->site($cid);
        $v = $request->validate([
            'slug' => 'required|string|max:80|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'name' => 'required|string|max:150',
            'tagline' => 'nullable|string|max:255',
            'theme' => 'required|in:dark,light',
            'published' => 'boolean',
            'about_title' => 'nullable|string|max:150', 'about_subtitle' => 'nullable|string|max:255',
            'about_name' => 'nullable|string|max:150', 'about_text' => 'nullable|string|max:10000',
            'skills' => 'nullable|array|max:12', 'skills.*.label' => 'required|string|max:60', 'skills.*.pct' => 'required|integer|min:0|max:100',
            'contact_subtitle' => 'nullable|string|max:255', 'contact_address' => 'nullable|string|max:500',
            'contact_phones' => 'nullable|string|max:500', 'contact_emails' => 'nullable|string|max:500',
            'map_url' => ['nullable', 'string', 'max:2000', 'regex:#^https://(www\.)?google\.[a-z.]+/maps/embed#'],
            'social' => 'nullable|array', 'social.*' => 'nullable|string|max:255',
            'footer_text' => 'nullable|string|max:255',
            'bg_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'pages' => 'nullable|array', 'pages.about' => 'boolean', 'pages.contact' => 'boolean',
            'template' => 'nullable|in:'.implode(',', array_keys(PortfolioThemes::ALL)),
            'menu_on_home' => 'boolean',
            // null = back to the default menu; items are cleaned by PortfolioThemes::cleanMenu().
            'menu' => 'nullable|array|max:12',
        ], ['map_url.regex' => 'Paste the Google Maps "Embed a map" link (https://www.google.com/maps/embed?...).', 'slug.regex' => 'Web address: lowercase letters, numbers and dashes only.']);
        abort_if(in_array($v['slug'], ['admin', 'api', 'assets'], true), 422, 'That web address is reserved.');
        abort_if(DB::table('portfolio_sites')->where('slug', $v['slug'])->where('company_id', '!=', $cid)->exists(), 422, 'That web address is already used by another company.');
        $social = collect($v['social'] ?? [])->only(['instagram', 'facebook', 'tiktok', 'youtube', 'twitter', 'whatsapp', 'website'])
            ->map(fn ($u) => trim((string) $u))->filter(fn ($u) => $u !== '' && preg_match('#^(https?://|wa\.me/|\+?\d)#i', $u))->all();

        DB::table('portfolio_sites')->where('id', $site->id)->update([
            'slug' => $v['slug'], 'name' => $v['name'], 'tagline' => $v['tagline'] ?? null, 'theme' => $v['theme'],
            'published' => (bool) ($v['published'] ?? false),
            'about_title' => $v['about_title'] ?? null, 'about_subtitle' => $v['about_subtitle'] ?? null,
            'about_name' => $v['about_name'] ?? null, 'about_text' => $v['about_text'] ?? null,
            'skills' => json_encode(array_values($v['skills'] ?? [])),
            'contact_subtitle' => $v['contact_subtitle'] ?? null, 'contact_address' => $v['contact_address'] ?? null,
            'contact_phones' => $v['contact_phones'] ?? null, 'contact_emails' => $v['contact_emails'] ?? null,
            'map_url' => $v['map_url'] ?? null, 'social' => json_encode($social), 'footer_text' => $v['footer_text'] ?? null,
            'bg_color' => $v['bg_color'] ?? null,
            'pages' => json_encode(['about' => (bool) ($v['pages']['about'] ?? true), 'contact' => (bool) ($v['pages']['contact'] ?? true)]),
            'template' => PortfolioThemes::key($v['template'] ?? ($site->template ?? null)),
            'menu_on_home' => (bool) ($v['menu_on_home'] ?? false),
            'menu' => $request->has('menu') && is_array($v['menu'] ?? null) ? json_encode(PortfolioThemes::cleanMenu($v['menu'])) : null,
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true, 'site' => $this->siteOut(DB::table('portfolio_sites')->find($site->id))]);
    }

    /** Store an uploaded image (resized, max $max px on the long side) in the company's portfolio folder. */
    private function saveImage(int $cid, $file, int $max = 1800): string
    {
        $dir = CompanyFiles::dir($cid, self::MODULE).'/'.date('Y/m');
        if (! is_dir(public_path($dir))) {
            mkdir(public_path($dir), 0755, true);
        }
        $name = Str::random(10).'-'.CompanyFiles::safeName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.jpg';
        $img = \Intervention\Image\ImageManagerStatic::make($file->getRealPath());
        try {
            $img->orientate();
        } catch (\Throwable $e) {
        }
        $img->resize($max, $max, function ($c) {
            $c->aspectRatio();
            $c->upsize();
        })->save(public_path($dir.'/'.$name), 85, 'jpg');

        return $dir.'/'.$name;
    }

    /** Delete a file only when it is one of this company's portfolio files. */
    private function dropFile(?string $path, int $cid): void
    {
        $p = (string) $path;
        if ($p !== '' && str_starts_with($p, 'files/company_'.$cid.'/'.self::MODULE.'/') && ! str_contains($p, '..') && is_file(public_path($p))) {
            @unlink(public_path($p));
        }
    }

    public function siteImage(Request $request)
    {
        $cid = $this->cid($request);
        $site = $this->site($cid);
        $col = ['about' => 'about_image', 'background' => 'bg_image'][$request->input('kind')] ?? 'logo';
        $path = null;
        if ($request->isMethod('post')) {
            $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:8192']);
            $path = $this->saveImage($cid, $request->file('image'), ['logo' => 900, 'about_image' => 1400, 'bg_image' => 2200][$col]);
        }
        $this->dropFile($site->{$col}, $cid);
        DB::table('portfolio_sites')->where('id', $site->id)->update([$col => $path, 'updated_at' => now()]);

        return response()->json(['success' => true, 'url' => $this->url($path)]);
    }

    /* ------------------------------------------------------------ Home sections */

    public const SECTION_TYPES = ['gallery', 'text', 'image', 'image_text', 'video', 'contact'];

    private function sectionsOut(int $cid)
    {
        return DB::table('portfolio_sections')->where('company_id', $cid)->orderBy('sort_order')->orderBy('id')->get()
            ->map(function ($s) {
                $a = (array) $s;
                $a['visible'] = (bool) $s->visible;
                $a['settings'] = array_merge(['featured_only' => true, 'show_all' => false], json_decode((string) $s->settings, true) ?: []);
                $a['image_url'] = $this->url($s->image);

                return $a;
            });
    }

    private function sectionData(Request $request): array
    {
        $v = $request->validate([
            'type' => 'required|in:'.implode(',', self::SECTION_TYPES),
            'title' => 'nullable|string|max:150', 'subtitle' => 'nullable|string|max:255', 'body' => 'nullable|string|max:10000',
            'video_url' => 'nullable|string|max:255',
            'button_label' => 'nullable|string|max:60', 'button_link' => ['nullable', 'string', 'max:255', 'regex:#^(https?://|/|mailto:|tel:)#i'],
            'bg_color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings' => 'nullable|array', 'settings.featured_only' => 'boolean', 'settings.show_all' => 'boolean',
            'visible' => 'boolean',
        ], ['button_link.regex' => 'Button link: a web address (https://...) or a page of the site (/portfolio/...).']);

        return [
            'type' => $v['type'], 'title' => $v['title'] ?? null, 'subtitle' => $v['subtitle'] ?? null, 'body' => $v['body'] ?? null,
            'video_url' => self::embedUrl($v['video_url'] ?? null), 'button_label' => $v['button_label'] ?? null, 'button_link' => $v['button_link'] ?? null,
            'bg_color' => $v['bg_color'] ?? null,
            'settings' => json_encode(['featured_only' => (bool) ($v['settings']['featured_only'] ?? true), 'show_all' => (bool) ($v['settings']['show_all'] ?? false)]),
            'visible' => (bool) ($v['visible'] ?? true), 'updated_at' => now(),
        ];
    }

    public function storeSection(Request $request)
    {
        $cid = $this->cid($request);
        $this->site($cid);
        $id = DB::table('portfolio_sections')->insertGetId($this->sectionData($request) + [
            'company_id' => $cid, 'sort_order' => (int) DB::table('portfolio_sections')->where('company_id', $cid)->max('sort_order') + 1, 'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'section' => $this->sectionsOut($cid)->firstWhere('id', $id)]);
    }

    public function updateSection(Request $request, $id)
    {
        $cid = $this->cid($request);
        $q = DB::table('portfolio_sections')->where('id', $id)->where('company_id', $cid);
        abort_unless((clone $q)->exists(), 404);
        $q->update($this->sectionData($request));

        return response()->json(['success' => true, 'section' => $this->sectionsOut($cid)->firstWhere('id', (int) $id)]);
    }

    public function destroySection(Request $request, $id)
    {
        $cid = $this->cid($request);
        $s = DB::table('portfolio_sections')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($s, 404);
        $this->dropFile($s->image, $cid);
        DB::table('portfolio_sections')->where('id', $s->id)->delete();

        return response()->json(['success' => true]);
    }

    public function reorderSections(Request $request)
    {
        $cid = $this->cid($request);
        $this->reorder('portfolio_sections', $cid, (array) $request->input('ids', []));

        return response()->json(['success' => true]);
    }

    /** POST portfolio/sections/{id}/image (file "image") | DELETE: the section's picture. */
    public function sectionImage(Request $request, $id)
    {
        $cid = $this->cid($request);
        $s = DB::table('portfolio_sections')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($s, 404);
        $path = null;
        if ($request->isMethod('post')) {
            $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:8192']);
            $path = $this->saveImage($cid, $request->file('image'), 2000);
        }
        $this->dropFile($s->image, $cid);
        DB::table('portfolio_sections')->where('id', $s->id)->update(['image' => $path, 'updated_at' => now()]);

        return response()->json(['success' => true, 'url' => $this->url($path)]);
    }

    /* ------------------------------------------------------------ categories */

    private function uniqueSlug(string $table, int $cid, string $text, ?int $exceptId = null): string
    {
        $base = Str::slug($text) ?: 'item';
        $slug = $base;
        for ($i = 2; DB::table($table)->where('company_id', $cid)->where('slug', $slug)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public function storeCategory(Request $request)
    {
        $cid = $this->cid($request);
        $v = $request->validate(['name' => 'required|string|max:80']);
        $id = DB::table('portfolio_categories')->insertGetId([
            'company_id' => $cid, 'name' => trim($v['name']), 'slug' => $this->uniqueSlug('portfolio_categories', $cid, $v['name']),
            'sort_order' => (int) DB::table('portfolio_categories')->where('company_id', $cid)->max('sort_order') + 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(DB::table('portfolio_categories')->find($id, ['id', 'name', 'slug', 'sort_order']));
    }

    public function updateCategory(Request $request, $id)
    {
        $cid = $this->cid($request);
        $v = $request->validate(['name' => 'required|string|max:80']);
        $q = DB::table('portfolio_categories')->where('id', $id)->where('company_id', $cid);
        abort_unless((clone $q)->exists(), 404);
        $q->update(['name' => trim($v['name']), 'slug' => $this->uniqueSlug('portfolio_categories', $cid, $v['name'], (int) $id), 'updated_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function destroyCategory(Request $request, $id)
    {
        $cid = $this->cid($request);
        abort_unless(DB::table('portfolio_categories')->where('id', $id)->where('company_id', $cid)->delete(), 404);
        // Take it off the projects that had it.
        foreach (DB::table('portfolio_projects')->where('company_id', $cid)->get(['id', 'categories']) as $p) {
            $ids = array_values(array_diff(array_map('intval', json_decode((string) $p->categories, true) ?: []), [(int) $id]));
            DB::table('portfolio_projects')->where('id', $p->id)->update(['categories' => json_encode($ids)]);
        }

        return response()->json(['success' => true]);
    }

    private function reorder(string $table, int $cid, array $ids, array $extra = []): void
    {
        foreach (array_values($ids) as $i => $id) {
            DB::table($table)->where('id', (int) $id)->where('company_id', $cid)->where($extra)->update(['sort_order' => $i + 1]);
        }
    }

    public function reorderCategories(Request $request)
    {
        $cid = $this->cid($request);
        $this->reorder('portfolio_categories', $cid, (array) $request->input('ids', []));

        return response()->json(['success' => true]);
    }

    /* ------------------------------------------------------------ projects */

    private function projectData(Request $request, int $cid): array
    {
        $v = $request->validate([
            'title' => 'required|string|max:150',
            'type' => 'required|in:image,slider,video',
            'description' => 'nullable|string|max:10000',
            'client' => 'nullable|string|max:150', 'location' => 'nullable|string|max:150',
            'project_date' => 'nullable|date',
            'video_url' => 'nullable|string|max:255',
            // "Visit website" link (2026-10-20): a full https address or a path on this server.
            'website_url' => ['nullable', 'string', 'max:255', 'regex:#^(https?://|/(?!/))#i'],
            'categories' => 'nullable|array', 'categories.*' => 'integer',
            'featured' => 'boolean', 'published' => 'boolean',
        ]);
        $cats = DB::table('portfolio_categories')->where('company_id', $cid)->whereIn('id', $v['categories'] ?? [])->pluck('id')->map(fn ($x) => (int) $x)->all();

        return [
            'title' => trim($v['title']), 'type' => $v['type'], 'description' => $v['description'] ?? null,
            'client' => $v['client'] ?? null, 'location' => $v['location'] ?? null, 'project_date' => $v['project_date'] ?? null,
            'video_url' => self::embedUrl($v['video_url'] ?? null), 'website_url' => trim((string) ($v['website_url'] ?? '')) ?: null,
            'categories' => json_encode($cats),
            'featured' => (bool) ($v['featured'] ?? true), 'published' => (bool) ($v['published'] ?? true), 'updated_at' => now(),
        ];
    }

    /** YouTube / Vimeo link -> its embed URL (anything else: kept only if it is already an https embed). */
    public static function embedUrl(?string $url): ?string
    {
        $u = trim((string) $url);
        if ($u === '') {
            return null;
        }
        if (preg_match('#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,})#', $u, $m)) {
            return 'https://www.youtube.com/embed/'.$m[1];
        }
        if (preg_match('#vimeo\.com/(?:video/)?(\d+)#', $u, $m)) {
            return 'https://player.vimeo.com/video/'.$m[1];
        }

        return str_starts_with($u, 'https://') ? $u : null;
    }

    public function storeProject(Request $request)
    {
        $cid = $this->cid($request);
        $data = $this->projectData($request, $cid);
        $id = DB::table('portfolio_projects')->insertGetId($data + [
            'company_id' => $cid, 'slug' => $this->uniqueSlug('portfolio_projects', $cid, $data['title']),
            'sort_order' => (int) DB::table('portfolio_projects')->where('company_id', $cid)->max('sort_order') + 1, 'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id, 'project' => $this->projectsOut($cid)->firstWhere('id', $id)]);
    }

    public function updateProject(Request $request, $id)
    {
        $cid = $this->cid($request);
        $q = DB::table('portfolio_projects')->where('id', $id)->where('company_id', $cid);
        abort_unless((clone $q)->exists(), 404);
        $data = $this->projectData($request, $cid);
        $q->update($data + ['slug' => $this->uniqueSlug('portfolio_projects', $cid, $data['title'], (int) $id)]);

        return response()->json(['success' => true, 'project' => $this->projectsOut($cid)->firstWhere('id', (int) $id)]);
    }

    public function destroyProject(Request $request, $id)
    {
        $cid = $this->cid($request);
        $p = DB::table('portfolio_projects')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($p, 404);
        $this->dropFile($p->cover, $cid);
        foreach (DB::table('portfolio_media')->where('project_id', $p->id)->where('company_id', $cid)->pluck('path') as $path) {
            $this->dropFile($path, $cid);
        }
        DB::table('portfolio_media')->where('project_id', $p->id)->where('company_id', $cid)->delete();
        DB::table('portfolio_projects')->where('id', $p->id)->delete();

        return response()->json(['success' => true]);
    }

    public function reorderProjects(Request $request)
    {
        $cid = $this->cid($request);
        $this->reorder('portfolio_projects', $cid, (array) $request->input('ids', []));

        return response()->json(['success' => true]);
    }

    public function projectCover(Request $request, $id)
    {
        $cid = $this->cid($request);
        $p = DB::table('portfolio_projects')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($p, 404);
        $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:8192']);
        $path = $this->saveImage($cid, $request->file('image'), 1400);
        $this->dropFile($p->cover, $cid);
        DB::table('portfolio_projects')->where('id', $p->id)->update(['cover' => $path, 'updated_at' => now()]);

        return response()->json(['success' => true, 'url' => $this->url($path)]);
    }

    public function addMedia(Request $request, $id)
    {
        $cid = $this->cid($request);
        $p = DB::table('portfolio_projects')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($p, 404);
        $request->validate(['images' => 'required|array|max:30', 'images.*' => 'image|mimes:jpg,jpeg,png,webp,gif|max:8192']);
        $order = (int) DB::table('portfolio_media')->where('project_id', $p->id)->max('sort_order');
        $out = [];
        foreach ($request->file('images') as $file) {
            $path = $this->saveImage($cid, $file);
            $mid = DB::table('portfolio_media')->insertGetId(['company_id' => $cid, 'project_id' => $p->id, 'path' => $path, 'sort_order' => ++$order, 'created_at' => now(), 'updated_at' => now()]);
            $out[] = ['id' => $mid, 'url' => $this->url($path)];
        }
        // No cover yet: the first photo becomes it.
        if (! $p->cover && $out) {
            DB::table('portfolio_projects')->where('id', $p->id)->update(['cover' => ltrim($out[0]['url'], '/')]);
        }

        return response()->json(['success' => true, 'media' => $out]);
    }

    public function destroyMedia(Request $request, $id)
    {
        $cid = $this->cid($request);
        $m = DB::table('portfolio_media')->where('id', $id)->where('company_id', $cid)->first();
        abort_unless($m, 404);
        // Still the cover? keep the file (the cover uses it), only the slide goes.
        $isCover = DB::table('portfolio_projects')->where('id', $m->project_id)->value('cover') === $m->path;
        if (! $isCover) {
            $this->dropFile($m->path, $cid);
        }
        DB::table('portfolio_media')->where('id', $m->id)->delete();

        return response()->json(['success' => true]);
    }

    public function reorderMedia(Request $request, $id)
    {
        $cid = $this->cid($request);
        $this->reorder('portfolio_media', $cid, (array) $request->input('ids', []), [['project_id', '=', (int) $id]]);

        return response()->json(['success' => true]);
    }

    /* ------------------------------------------------------------ messages */

    public function messages(Request $request)
    {
        $cid = $this->cid($request);

        return response()->json(['messages' => DB::table('portfolio_messages')->where('company_id', $cid)->orderByDesc('id')->limit(500)->get()]);
    }

    public function updateMessage(Request $request, $id)
    {
        $cid = $this->cid($request);
        abort_unless(DB::table('portfolio_messages')->where('id', $id)->where('company_id', $cid)->update(['is_read' => (bool) $request->input('is_read', true), 'updated_at' => now()]) !== false, 404);

        return response()->json(['success' => true]);
    }

    public function destroyMessage(Request $request, $id)
    {
        $cid = $this->cid($request);
        DB::table('portfolio_messages')->where('id', $id)->where('company_id', $cid)->delete();

        return response()->json(['success' => true]);
    }
}
