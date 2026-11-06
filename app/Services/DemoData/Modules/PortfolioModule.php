<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use App\Services\DemoData\PortfolioDemoCatalog;
use App\Support\CompanyFiles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portfolio: example works with real photos, following what the company does
 * (App\Support\BusinessTypes): landscaping, IT services / consulting, unisex
 * salon or barber shop (other types get the IT set). Categories, works (cover +
 * photos, some as sliders), and three Contact form messages.
 *
 * The photos come from resources/demo/portfolio/{set}/ (built by
 * database/scripts/portfolio_demo_photos.php) and are copied into the
 * company's own files/company_{id}/portfolio/demo/ folder; purge() deletes
 * those copies when the demo is removed. The website itself (portfolio_sites)
 * is real setup: created if missing, its empty About / Contact texts filled in,
 * never removed.
 */
class PortfolioModule extends DemoModule
{
    public function key(): string
    {
        return 'portfolio';
    }

    public function label(): string
    {
        return 'Portfolio (works with photos for the business type)';
    }

    /** No photo set for a travel / bus company yet (it got the IT works). */
    public function skipBusinessTypes(): array
    {
        return ['tourism'];
    }

    public function dataTables(): array
    {
        return ['portfolio_projects'];
    }

    public function requiredTables(): array
    {
        return ['portfolio_sites', 'portfolio_categories', 'portfolio_projects', 'portfolio_media'];
    }

    public function generate(DemoContext $ctx): array
    {
        $cid = $ctx->companyId;
        $set = PortfolioDemoCatalog::setFor($ctx->businessType);
        $data = PortfolioDemoCatalog::SETS[$set];
        $src = resource_path('demo/portfolio/'.$set);
        $destRel = CompanyFiles::dir($cid, 'portfolio').'/demo';
        if (! is_dir(public_path($destRel))) {
            mkdir(public_path($destRel), 0755, true);
        }

        $this->ensureSite($cid, $set);

        // Categories: reuse the company's own by name, else create (demo).
        $cats = [];
        $sort = (int) DB::table('portfolio_categories')->where('company_id', $cid)->max('sort_order');
        $newCats = 0;
        foreach ($data['categories'] as $name) {
            $id = DB::table('portfolio_categories')->where('company_id', $cid)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id');
            if (! $id) {
                $slug = $this->uniqueSlug('portfolio_categories', $cid, Str::slug($name));
                $id = $ctx->insert('portfolio_categories', ['name' => $name, 'slug' => $slug, 'sort_order' => ++$sort]);
                $newCats++;
            }
            $cats[$name] = $id;
        }

        $order = (int) DB::table('portfolio_projects')->where('company_id', $cid)->max('sort_order');
        $works = $photos = 0;
        foreach ($data['works'] as $i => [$title, $category, $client, $location, $description, $files]) {
            $copied = [];
            foreach ($files as [$file]) {
                if (! is_file("$src/$file")) {
                    continue;
                }
                $rel = $destRel.'/'.Str::lower(Str::random(6)).'-'.$file;
                copy("$src/$file", public_path($rel));
                $copied[] = $rel;
            }
            if (! $copied) {
                continue;
            }
            $projectId = $ctx->insert('portfolio_projects', [
                'title' => $title,
                'slug' => $this->uniqueSlug('portfolio_projects', $cid, Str::slug($title)),
                'type' => count($copied) > 1 ? 'slider' : 'image',
                'cover' => $copied[0],
                'description' => $description,
                'client' => $client,
                'location' => $location,
                'project_date' => now()->subDays(12 + $i * 23)->toDateString(),
                'categories' => json_encode([$cats[$category]]),
                'featured' => true,
                'published' => true,
                'sort_order' => ++$order,
            ]);
            foreach ($copied as $n => $path) {
                $ctx->insert('portfolio_media', ['project_id' => $projectId, 'path' => $path, 'sort_order' => $n + 1]);
                $photos++;
            }
            $works++;
        }

        // A few Contact form messages (Portfolio -> Messages).
        $messages = 0;
        foreach ([
            ['Jennifer Adams', 'jennifer.adams@example.test', '+1 973 555 0142', 'Hi! I saw your work on the website. Could you send me a quote for something similar?'],
            ['Robert Chen', 'robert.chen@example.test', null, 'Do you have availability next month? I would like to book a consultation.'],
            ['Maria Lopez', null, '+1 201 555 0188', 'Love your portfolio! What are your prices? Please call me back.'],
        ] as $k => [$name, $email, $phone, $text]) {
            $ctx->insert('portfolio_messages', [
                'name' => $name, 'email' => $email, 'phone' => $phone, 'message' => $text, 'ip' => '127.0.0.1',
                'is_read' => $k === 2, 'created_at' => now()->subDays(1 + $k * 3), 'updated_at' => now()->subDays(1 + $k * 3),
            ]);
            $messages++;
        }

        return [
            'Business type' => \App\Support\BusinessTypes::LABELS[$ctx->businessType] ?? $ctx->businessType,
            'Photo set' => $set,
            'Categories' => $newCats,
            'Works' => $works,
            'Photos' => $photos,
            'Messages' => $messages,
        ];
    }

    /** Delete the photo copies of this company's demo works before their rows go. */
    public function purge(DemoContext $ctx): void
    {
        $prefix = CompanyFiles::dir($ctx->companyId, 'portfolio').'/demo/';
        $paths = DB::table('portfolio_media')->whereIn('id', $ctx->demoIds('portfolio_media'))->pluck('path')
            ->merge(DB::table('portfolio_projects')->whereIn('id', $ctx->demoIds('portfolio_projects'))->pluck('cover'));
        foreach ($paths->filter()->unique() as $p) {
            if (str_starts_with($p, $prefix) && ! str_contains($p, '..') && is_file(public_path($p))) {
                @unlink(public_path($p));
            }
        }
    }

    /** The site (real setup): create it if missing; fill only the About / Contact texts that are empty. */
    private function ensureSite(int $cid, string $set): void
    {
        $site = DB::table('portfolio_sites')->where('company_id', $cid)->first();
        $company = DB::table('companies')->where('id', $cid)->first();
        $name = (string) ($company->name ?? 'Portfolio');
        if (! $site) {
            $slug = $this->uniqueSlug('portfolio_sites', null, Str::slug($name) ?: 'portfolio-'.$cid);
            DB::table('portfolio_sites')->insert([
                'company_id' => $cid, 'slug' => $slug, 'name' => $name, 'theme' => 'dark', 'published' => false,
                'about_title' => 'About us', 'contact_subtitle' => 'Get in touch with us', 'skills' => json_encode([]), 'social' => json_encode([]),
                'pages' => json_encode(['about' => true, 'contact' => true]), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $site = DB::table('portfolio_sites')->where('company_id', $cid)->first();
        }
        if (! DB::table('portfolio_sections')->where('company_id', $cid)->exists()) {
            DB::table('portfolio_sections')->insert([
                'company_id' => $cid, 'type' => 'gallery', 'settings' => json_encode(['featured_only' => true, 'show_all' => false]),
                'visible' => true, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        [$heading, $text, $skills] = PortfolioDemoCatalog::ABOUT[$set];
        // Only what is still empty -- the company's own texts are never replaced.
        $fill = array_filter([
            'about_name' => $site->about_name ? null : $heading,
            'about_text' => $site->about_text ? null : $text,
            'skills' => (json_decode((string) $site->skills, true) ?: []) ? null : json_encode(array_map(fn ($s) => ['label' => $s[0], 'pct' => $s[1]], $skills)),
            'contact_phones' => $site->contact_phones ? null : ($company->phone ?? null),
            'contact_emails' => $site->contact_emails ? null : ($company->email ?? null),
        ]);
        if ($fill) {
            DB::table('portfolio_sites')->where('id', $site->id)->update($fill + ['updated_at' => now()]);
        }
    }

    private function uniqueSlug(string $table, ?int $cid, string $base): string
    {
        $base = $base ?: 'item';
        $slug = $base;
        for ($i = 2; DB::table($table)->when($cid, fn ($q) => $q->where('company_id', $cid))->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }
}
