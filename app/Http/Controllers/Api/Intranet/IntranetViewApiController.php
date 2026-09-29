<?php

namespace App\Http\Controllers\Api\Intranet;

use App\Models\IntranetAlbum;
use App\Models\IntranetEvent;
use App\Models\IntranetFile;
use App\Models\IntranetPage;
use App\Models\IntranetPhoto;
use App\Models\IntranetPost;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Intranet -> the employee side (intranet_view, acting company only). Only
 * published content; drafts / scheduled posts / unpublished pages and albums
 * are invisible here (404).
 *
 *  GET intranet/home                    dashboard: news, events, birthdays, files, photos, pages
 *  GET intranet/news                    ?category=&search=&page=
 *  GET intranet/news/{id}
 *  GET intranet/pages                   navigation list
 *  GET intranet/pages/{slug}
 *  GET intranet/files                   ?folder=&search=
 *  GET intranet/files/{id}/download     streams the private file
 *  GET intranet/albums
 *  GET intranet/albums/{id}
 *  GET intranet/calendar                ?month=YYYY-MM  -> events + birthdays + holidays
 *  GET intranet/birthdays               ?days=30  -> this month + upcoming
 */
class IntranetViewApiController extends IntranetBaseController
{
    public function home(Request $request)
    {
        $companyId = $this->viewCompany($request);

        $news = IntranetPost::where('company_id', $companyId)->visible()->feedOrder()->with('author')->limit(6)->get();
        $news->each(fn ($p) => $this->withExcerpt($p));

        $events = IntranetEvent::where('company_id', $companyId)
            ->whereRaw('COALESCE(ends_at, starts_at) >= ?', [now()->startOfDay()])
            ->orderBy('starts_at')->limit(6)->get();

        $albums = IntranetAlbum::where('company_id', $companyId)->where('published', true)->orderBy('sort_order')->orderByDesc('id')->limit(4)->get();
        $photos = IntranetPhoto::where('company_id', $companyId)
            ->whereIn('album_id', IntranetAlbum::where('company_id', $companyId)->where('published', true)->select('id'))
            ->orderByDesc('id')->limit(8)->get();

        return response()->json([
            'news' => $news,
            'events' => $events,
            'birthdays_month' => $this->birthdaysInMonth($companyId, (int) now()->month),
            'upcoming_birthdays' => $this->upcomingBirthdays($companyId, 30),
            'files' => IntranetFile::where('company_id', $companyId)->with('uploader')->orderByDesc('created_at')->orderByDesc('id')->limit(6)->get(),
            'albums' => IntranetGalleryApiController::withCover($albums),
            'photos' => $photos,
            'pages' => $this->navPages($companyId),
            'can_manage' => $this->userCan($request, 'intranet_manage'),
        ]);
    }

    /* ---------------- news ---------------- */

    public function news(Request $request)
    {
        $companyId = $this->viewCompany($request);
        $request->validate([
            'category' => ['nullable', Rule::in(IntranetPost::CATEGORIES)],
            'search' => 'nullable|string|max:100',
        ]);
        $q = IntranetPost::where('company_id', $companyId)->visible()->with('author');
        if ($c = $request->input('category')) {
            $q->where('category', $c);
        }
        if ($s = trim((string) $request->input('search'))) {
            $q->where('title', 'like', '%'.$s.'%');
        }
        $page = $q->feedOrder()->paginate(12);
        $page->getCollection()->each(fn ($p) => $this->withExcerpt($p));

        return response()->json($page);
    }

    public function newsShow(Request $request, $id)
    {
        $companyId = $this->viewCompany($request);
        $post = IntranetPost::where('company_id', $companyId)->visible()->whereKey((int) $id)->with('author')->first();
        abort_unless($post, 404);

        return response()->json($post);
    }

    private function withExcerpt(IntranetPost $p): IntranetPost
    {
        $p->setAttribute('summary', $p->excerpt ?: $this->plainExcerpt($p->body));

        return $p->makeHidden('body');
    }

    /* ---------------- pages ---------------- */

    private function navPages(int $companyId)
    {
        return IntranetPage::where('company_id', $companyId)->where('published', true)
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'title', 'slug']);
    }

    public function pages(Request $request)
    {
        return response()->json(['data' => $this->navPages($this->viewCompany($request))]);
    }

    public function pageShow(Request $request, string $slug)
    {
        $companyId = $this->viewCompany($request);
        $page = IntranetPage::where('company_id', $companyId)->where('published', true)->where('slug', $slug)->first();
        abort_unless($page, 404);

        return response()->json($page);
    }

    /* ---------------- files ---------------- */

    public function files(Request $request)
    {
        $companyId = $this->viewCompany($request);
        $request->validate(['search' => 'nullable|string|max:100', 'folder' => 'nullable|string|max:120']);

        return response()->json([
            'data' => IntranetFilesApiController::listQuery($request, $companyId)->get(),
            'folders' => IntranetFilesApiController::folders($companyId),
        ]);
    }

    public function download(Request $request, $id)
    {
        $companyId = $this->viewCompany($request);
        $f = $this->own(IntranetFile::class, $companyId, $id);
        $abs = $f->absolutePath();
        abort_unless(str_starts_with($f->path, 'intranet/company_'.$companyId.'/files/') && ! str_contains($f->path, '..') && is_file($abs), 404);
        IntranetFile::whereKey($f->id)->increment('downloads');

        return response()->download($abs, $f->original_name, [
            'Content-Type' => $f->mime ?: 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /* ---------------- gallery ---------------- */

    public function albums(Request $request)
    {
        $companyId = $this->viewCompany($request);
        $albums = IntranetAlbum::where('company_id', $companyId)->where('published', true)->orderBy('sort_order')->orderByDesc('id')->get();

        return response()->json(['data' => IntranetGalleryApiController::withCover($albums)]);
    }

    public function albumShow(Request $request, $id)
    {
        $companyId = $this->viewCompany($request);
        $album = IntranetAlbum::where('company_id', $companyId)->where('published', true)->whereKey((int) $id)->with('photos')->first();
        abort_unless($album, 404);

        return response()->json($album);
    }

    /* ---------------- calendar / birthdays ---------------- */

    public function calendar(Request $request)
    {
        $companyId = $this->viewCompany($request);
        $request->validate(['month' => ['nullable', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        $month = $request->input('month') ? Carbon::createFromFormat('Y-m-d', $request->input('month').'-01')->startOfDay() : now()->startOfMonth();
        // The month grid shows a few days of the neighbouring months too.
        $from = $month->copy()->startOfMonth()->subDays(7);
        $to = $month->copy()->endOfMonth()->addDays(14);

        $events = IntranetEvent::where('company_id', $companyId)->overlapping($from, $to)->orderBy('starts_at')->get();

        $birthdays = [];
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
            foreach ($this->birthdaysInMonth($companyId, (int) $m->month, (int) $m->year) as $b) {
                if ($b['date'] >= $from->toDateString() && $b['date'] <= $to->toDateString()) {
                    $birthdays[$b['date'].'#'.$b['id']] = $b;
                }
            }
        }
        ksort($birthdays);
        $birthdays = array_values($birthdays);

        $holidays = [];
        if (Schema::hasTable('holidays') && Schema::hasColumn('holidays', 'company_id')) {
            $holidays = DB::table('holidays')->where('company_id', $companyId)->whereNull('deleted_at')
                ->where('start_date', '<=', $to->toDateString())
                ->whereRaw('COALESCE(end_date, start_date) >= ?', [$from->toDateString()])
                ->orderBy('start_date')->get(['id', 'title', 'start_date', 'end_date', 'description']);
        }

        return response()->json([
            'month' => $month->format('Y-m'),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'events' => $events,
            'birthdays' => $birthdays,
            'holidays' => $holidays,
        ]);
    }

    public function birthdays(Request $request)
    {
        $companyId = $this->viewCompany($request);
        $request->validate(['days' => 'nullable|integer|min:1|max:366']);
        $days = (int) ($request->input('days') ?: 30);

        return response()->json([
            'month' => $this->birthdaysInMonth($companyId, (int) now()->month),
            'upcoming' => $this->upcomingBirthdays($companyId, $days),
        ]);
    }

    /** Active employees of the company with a birth date (not deleted, not gone). */
    private function activeEmployees(int $companyId)
    {
        $today = now()->toDateString();

        return DB::table('employees')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('birth_date')
            ->where(fn ($w) => $w->whereNull('leaving_date')->orWhere('leaving_date', '>=', $today))
            ->get(['id', 'firstname', 'lastname', 'birth_date']);
    }

    /** Birthday of $birth (Y-m-d) in $year; Feb 29 falls on Feb 28 in non-leap years. */
    private function birthdayIn(string $birth, int $year): ?Carbon
    {
        try {
            $b = Carbon::parse($birth);
        } catch (\Throwable $e) {
            return null;
        }
        $day = ($b->month === 2 && $b->day === 29 && ! Carbon::create($year, 1, 1)->isLeapYear()) ? 28 : $b->day;

        return Carbon::create($year, $b->month, $day)->startOfDay();
    }

    private function person($e, Carbon $date): array
    {
        return [
            'id' => $e->id,
            'name' => trim($e->firstname.' '.$e->lastname),
            'initials' => mb_strtoupper(mb_substr((string) $e->firstname, 0, 1).mb_substr((string) $e->lastname, 0, 1)),
            'date' => $date->toDateString(),
            'day' => $date->day,
            'month' => $date->month,
            'is_today' => $date->isToday(),
        ];
    }

    private function birthdaysInMonth(int $companyId, int $month, ?int $year = null): array
    {
        $year = $year ?: (int) now()->year;
        $out = [];
        foreach ($this->activeEmployees($companyId) as $e) {
            $d = $this->birthdayIn((string) $e->birth_date, $year);
            if ($d && $d->month === $month) {
                $out[] = $this->person($e, $d);
            }
        }
        usort($out, fn ($a, $b) => [$a['day'], $a['name']] <=> [$b['day'], $b['name']]);

        return $out;
    }

    private function upcomingBirthdays(int $companyId, int $days): array
    {
        $today = now()->startOfDay();
        $limit = $today->copy()->addDays($days);
        $out = [];
        foreach ($this->activeEmployees($companyId) as $e) {
            $d = $this->birthdayIn((string) $e->birth_date, (int) $today->year);
            if ($d && $d->lt($today)) {
                $d = $this->birthdayIn((string) $e->birth_date, (int) $today->year + 1);
            }
            if ($d && $d->lte($limit)) {
                $row = $this->person($e, $d);
                $row['in_days'] = (int) $today->diffInDays($d);
                $out[] = $row;
            }
        }
        usort($out, fn ($a, $b) => [$a['date'], $a['name']] <=> [$b['date'], $b['name']]);

        return $out;
    }
}
