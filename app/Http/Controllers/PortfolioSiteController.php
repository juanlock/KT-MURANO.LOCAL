<?php

namespace App\Http\Controllers;

use App\Support\ModuleFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Portfolio module -- the public website of a company (layout of
 * KT-TOOLS/Porfolio, assets in public/portfolio-theme):
 *
 *  /portfolio/{site}                    Home: the featured works, filter buttons
 *  /portfolio/{site}/work               Portfolio: every work, "All" + filters
 *  /portfolio/{site}/project/{slug}     a work: one image / slider / video + details, prev / next
 *  /portfolio/{site}/about              About
 *  /portfolio/{site}/contact            Contact (form -> portfolio_messages)
 *
 * Only a published site (and only published works) is shown; signed-in staff
 * of the company may preview an unpublished one. The "portfolio" module off
 * for the company = 404.
 */
class PortfolioSiteController extends Controller
{
    private function site(string $slug): object
    {
        $site = DB::table('portfolio_sites')->where('slug', $slug)->first();
        abort_unless($site && ModuleFlags::enabled((int) $site->company_id, 'portfolio'), 404);
        if (! $site->published) {
            $u = auth()->user();
            abort_unless($u && ($u->type === 'SuperAdmin' || (int) $u->company_id === (int) $site->company_id), 404);
            $site->preview = true;
        }
        $site->skills = json_decode((string) $site->skills, true) ?: [];
        $site->social = json_decode((string) $site->social, true) ?: [];
        $site->pages = array_merge(['about' => true, 'contact' => true], json_decode((string) ($site->pages ?? ''), true) ?: []);

        return $site;
    }

    private function categories(object $site)
    {
        return DB::table('portfolio_categories')->where('company_id', $site->company_id)->orderBy('sort_order')->orderBy('id')->get();
    }

    private function projects(object $site, bool $featuredOnly = false)
    {
        $cats = $this->categories($site)->keyBy('id');

        return DB::table('portfolio_projects')->where('company_id', $site->company_id)->where('published', true)
            ->when($featuredOnly, fn ($q) => $q->where('featured', true))
            ->whereNotNull('cover')->orderBy('sort_order')->orderBy('id')->get()
            ->map(function ($p) use ($cats) {
                $ids = array_map('intval', json_decode((string) $p->categories, true) ?: []);
                $p->category_slugs = collect($ids)->map(fn ($id) => $cats[$id]->slug ?? null)->filter()->values()->all();

                return $p;
            });
    }

    /**
     * Home: the company's sections, top to bottom (Portfolio -> Home sections).
     * A gallery section carries its works (featured only, or all) and filters.
     */
    public function home(string $site)
    {
        $s = $this->site($site);
        $sections = DB::table('portfolio_sections')->where('company_id', $s->company_id)->where('visible', true)
            ->orderBy('sort_order')->orderBy('id')->get()
            ->map(function ($sec) use ($s) {
                $sec->settings = array_merge(['featured_only' => true, 'show_all' => false], json_decode((string) $sec->settings, true) ?: []);
                if ($sec->type === 'gallery') {
                    $projects = $this->projects($s, $sec->settings['featured_only']);
                    // Nothing marked "On the Home page": show every work rather than nothing.
                    if ($projects->isEmpty() && $sec->settings['featured_only']) {
                        $projects = $this->projects($s);
                    }
                    $sec->projects = $projects;
                    $sec->categories = $this->usedCategories($s, $projects);
                }

                return $sec;
            });

        return view('portfolio.home', ['site' => $s, 'page' => 'home', 'sections' => $sections]);
    }

    public function work(string $site)
    {
        $s = $this->site($site);
        $projects = $this->projects($s);

        return view('portfolio.gallery', ['site' => $s, 'page' => 'work', 'categories' => $this->usedCategories($s, $projects), 'projects' => $projects, 'showAll' => true]);
    }

    /** Only the filter buttons that have at least one work shown. */
    private function usedCategories(object $s, $projects)
    {
        $used = $projects->pluck('category_slugs')->flatten()->unique()->all();

        return $this->categories($s)->filter(fn ($c) => in_array($c->slug, $used, true))->values();
    }

    public function project(string $site, string $slug)
    {
        $s = $this->site($site);
        $list = $this->projects($s)->values();
        $i = $list->search(fn ($p) => $p->slug === $slug);
        abort_if($i === false, 404);
        $p = $list[$i];
        $media = DB::table('portfolio_media')->where('project_id', $p->id)->orderBy('sort_order')->orderBy('id')->pluck('path')->all();
        $slides = $media ?: array_filter([$p->cover]);
        $catNames = $this->categories($s)->whereIn('slug', $p->category_slugs)->pluck('name')->all();

        return view('portfolio.project', [
            'site' => $s, 'page' => 'work', 'project' => $p, 'slides' => $slides, 'categoryNames' => $catNames,
            'prev' => $list[$i - 1] ?? $list->last(), 'next' => $list[$i + 1] ?? $list->first(), 'count' => $list->count(),
        ]);
    }

    public function about(string $site)
    {
        $s = $this->site($site);
        abort_unless($s->pages['about'], 404);

        return view('portfolio.about', ['site' => $s, 'page' => 'about']);
    }

    public function contact(string $site)
    {
        $s = $this->site($site);
        abort_unless($s->pages['contact'], 404);

        return view('portfolio.contact', ['site' => $s, 'page' => 'contact']);
    }

    public function sendContact(Request $request, string $site)
    {
        $s = $this->site($site);
        abort_unless($s->pages['contact'], 404);
        // Honeypot + time-to-submit: bots fill the hidden field / post at once.
        if ($request->filled('website') || (time() - (int) $request->input('ts', 0)) < 3) {
            return redirect()->route('portfolio.contact', $s->slug)->with('pf_sent', true);
        }
        $v = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'nullable|email|max:190|required_without:phone',
            'phone' => 'nullable|string|max:60|required_without:email',
            'message' => 'required|string|max:5000',
        ], ['email.required_without' => 'Leave an email or a phone number.', 'phone.required_without' => 'Leave an email or a phone number.']);
        DB::table('portfolio_messages')->insert([
            'company_id' => $s->company_id, 'name' => strip_tags($v['name']), 'email' => $v['email'] ?? null,
            'phone' => isset($v['phone']) ? strip_tags($v['phone']) : null, 'message' => strip_tags($v['message']),
            'ip' => $request->ip(), 'is_read' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect()->route('portfolio.contact', $s->slug)->with('pf_sent', true);
    }
}
