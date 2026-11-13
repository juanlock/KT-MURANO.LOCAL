<?php

namespace App\Http\Controllers\Api\Portfolio;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Http\Controllers\Controller;
use App\Services\Helpdesk\HelpdeskAccess;
use App\Support\BusinessTypes;
use App\Support\CompanyFiles;
use App\Support\CompanyNames;
use App\Support\ModuleFlags;
use App\Support\PortfolioCatalog;
use App\Support\PublicUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portfolio -> Tablet catalogs (2026-10-25), back office. Permission
 * portfolio_manage (open / list also for whoever can use the POS); module
 * "portfolio". Many catalogs per company, types menu / models.
 *
 *  GET    portfolio/catalogs                     list (AdminSystem: every company)
 *  GET    portfolio/catalogs/launch              published catalogs of the acting company (header button)
 *  POST   portfolio/catalogs {name, type}
 *  GET    portfolio/catalogs/{id}                catalog + settings + groups + items
 *  PUT    portfolio/catalogs/{id}                {name, status, settings}
 *  DELETE portfolio/catalogs/{id}
 *  POST   portfolio/catalogs/{id}/image          (file "image") -> {path, url}
 *  POST   portfolio/catalogs/{id}/groups {name}; POST .../groups/reorder {ids}
 *  PUT    portfolio/catalogs/groups/{gid} {name, image}; DELETE portfolio/catalogs/groups/{gid}
 *  POST   portfolio/catalogs/groups/{gid}/items {product_ids}; POST .../items/reorder {ids}
 *  PUT    portfolio/catalogs/items/{iid} {options}; DELETE portfolio/catalogs/items/{iid}
 *  POST   portfolio/catalogs/items/{iid}/photos (files "photos[]", also camera shots); PUT .../photos {photos: [paths]}  model photos
 *  POST   portfolio/catalogs/items/{iid}/photos/url {url}   a model photo from a web link
 *  GET    portfolio/catalogs/{id}/products?search  the company's products to add
 *  GET    portfolio/catalogs/{id}/orders           orders sent from the tablet
 */
class CatalogsController extends Controller
{
    use ScopesToCompany;

    private function can(Request $request, bool $manage = true): void
    {
        $u = $request->user('api');
        $ok = HelpdeskAccess::can($u, 'portfolio_manage') || (! $manage && HelpdeskAccess::can($u, 'Pos_view'));
        abort_unless($ok, 403, 'You do not have permission for Portfolio.');
    }

    private function mayActOn(Request $request, int $companyId): bool
    {
        $u = $request->user('api');
        if ($u && $u->type === 'SuperAdmin') {
            $list = $this->listCompanyId($request);

            return $list === null || $list === $companyId;
        }

        return (int) ($u->company_id ?? 0) === $companyId;
    }

    private function catalog(Request $request, int $id): object
    {
        $c = DB::table('portfolio_catalogs')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($c && $this->mayActOn($request, (int) $c->company_id), 404);
        ModuleFlags::abortUnlessEnabled((int) $c->company_id, 'portfolio');

        return $c;
    }

    private function links(object $c): array
    {
        $page = PublicUrl::base((int) $c->company_id).'/c/'.$c->public_key;

        return ['page' => $page, 'relative' => '/c/'.$c->public_key];
    }

    private function out(object $c, bool $full = false): array
    {
        $a = ['id' => $c->id, 'company_id' => $c->company_id, 'type' => $c->type, 'name' => $c->name, 'public_key' => $c->public_key,
            'status' => (bool) $c->status, 'updated_at' => $c->updated_at, 'links' => $this->links($c)];
        if (! $full) {
            return $a;
        }
        $s = PortfolioCatalog::settings(json_decode((string) $c->settings, true), $c->type);
        $a['settings'] = $s;
        $a['logo_url'] = PortfolioCatalog::url($s['logo']);
        $a['slide_urls'] = array_map([PortfolioCatalog::class, 'url'], $s['slides']);
        $a['currency'] = PortfolioCatalog::currency((int) $c->company_id);
        $a['groups'] = DB::table('portfolio_catalog_groups')->where('catalog_id', $c->id)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn ($g) => ['id' => $g->id, 'name' => $g->name, 'image' => $g->image, 'image_url' => PortfolioCatalog::url($g->image)])->values();
        $a['items'] = DB::table('portfolio_catalog_items as i')->leftJoin('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.catalog_id', $c->id)->orderBy('i.sort_order')->orderBy('i.id')
            ->get(['i.id', 'i.group_id', 'i.product_id', 'i.options', 'i.photos', 'p.name', 'p.code', 'p.price', 'p.image', 'p.deleted_at', 'p.is_active'])
            ->map(function ($i) use ($c) {
                $photos = PortfolioCatalog::photos($i->photos, (int) $c->company_id);

                return ['id' => $i->id, 'group_id' => $i->group_id, 'product_id' => $i->product_id, 'name' => $i->name ?? '(deleted product)',
                    'code' => $i->code, 'price' => (float) $i->price, 'image_url' => PortfolioCatalog::productImage($i->image),
                    'available' => $i->name !== null && ! $i->deleted_at && $i->is_active !== 0,
                    'options' => (object) (json_decode((string) $i->options, true) ?: []),
                    'photos' => array_map(fn ($p) => ['path' => $p, 'url' => PortfolioCatalog::url($p)], $photos)];
            })->values();

        return $a;
    }

    public function index(Request $request)
    {
        $this->can($request, false);
        $q = DB::table('portfolio_catalogs')->whereNull('deleted_at');
        $this->scopeListToCompany($q, $request);
        $rows = $q->orderBy('name')->get();
        $groups = DB::table('portfolio_catalog_groups')->whereIn('catalog_id', $rows->pluck('id'))->selectRaw('catalog_id, count(*) n')->groupBy('catalog_id')->pluck('n', 'catalog_id');
        $items = DB::table('portfolio_catalog_items')->whereIn('catalog_id', $rows->pluck('id'))->selectRaw('catalog_id, count(*) n')->groupBy('catalog_id')->pluck('n', 'catalog_id');
        $orders = DB::table('portfolio_catalog_orders')->whereIn('catalog_id', $rows->pluck('id'))->where('created_at', '>=', now()->startOfDay())
            ->selectRaw('catalog_id, count(*) n')->groupBy('catalog_id')->pluck('n', 'catalog_id');

        return response()->json(['catalogs' => CompanyNames::attach($rows->map(fn ($c) => $this->out($c) + [
            'groups' => (int) ($groups[$c->id] ?? 0), 'items' => (int) ($items[$c->id] ?? 0), 'orders_today' => (int) ($orders[$c->id] ?? 0),
        ])->all())]);
    }

    /** The header button (like POS): the acting company's published catalogs. */
    public function launch(Request $request)
    {
        $this->can($request, false);
        $cid = (int) $this->resolveActingCompanyId($request);
        if (! ModuleFlags::enabled($cid, 'portfolio') && optional($request->user('api'))->type !== 'SuperAdmin') {
            return response()->json(['catalogs' => []]);
        }
        $rows = DB::table('portfolio_catalogs')->where('company_id', $cid)->whereNull('deleted_at')->where('status', true)->orderBy('name')->get();

        return response()->json(['catalogs' => $rows->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'type' => $c->type, 'url' => $this->links($c)['relative']])->values()]);
    }

    public function store(Request $request)
    {
        $this->can($request);
        $cid = (int) $this->resolveActingCompanyId($request);
        abort_if($cid === 999, 422, 'Pick a company first.');
        ModuleFlags::abortUnlessEnabled($cid, 'portfolio');
        $v = $request->validate(['name' => 'required|string|max:150', 'type' => 'required|in:menu,models']);
        $lang = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('default_language') === 'en' ? 'en' : 'es';
        do {
            $key = Str::lower(Str::random(10));
        } while (DB::table('portfolio_catalogs')->where('public_key', $key)->exists());
        $id = DB::table('portfolio_catalogs')->insertGetId([
            'company_id' => $cid, 'type' => $v['type'], 'name' => strip_tags($v['name']), 'public_key' => $key, 'status' => true,
            'settings' => json_encode(PortfolioCatalog::defaults($v['type'], $lang), JSON_UNESCAPED_UNICODE),
            'created_by' => $request->user('api')->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['catalog' => $this->out(DB::table('portfolio_catalogs')->find($id), true)], 201);
    }

    public function show(Request $request, int $id)
    {
        $this->can($request);

        return response()->json(['catalog' => $this->out($this->catalog($request, $id), true)]);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);
        $c = $this->catalog($request, $id);
        $v = $request->validate(['name' => 'required|string|max:150', 'status' => 'boolean', 'settings' => 'array']);
        $set = ['name' => strip_tags($v['name']), 'updated_at' => now()];
        if (array_key_exists('status', $v)) {
            $set['status'] = (bool) $v['status'];
        }
        if (array_key_exists('settings', $v)) {
            $s = PortfolioCatalog::settings($v['settings'], $c->type);
            // Pictures must be this company's files.
            $own = fn ($p) => $p === '' || str_starts_with($p, 'files/company_'.$c->company_id.'/');
            $s['logo'] = $own($s['logo']) ? $s['logo'] : '';
            $s['slides'] = array_values(array_filter($s['slides'], $own));
            $set['settings'] = json_encode($s, JSON_UNESCAPED_UNICODE);
        }
        DB::table('portfolio_catalogs')->where('id', $c->id)->update($set);

        return response()->json(['catalog' => $this->out(DB::table('portfolio_catalogs')->find($c->id), true)]);
    }

    public function destroy(Request $request, int $id)
    {
        $this->can($request);
        $c = $this->catalog($request, $id);
        DB::table('portfolio_catalogs')->where('id', $c->id)->update(['deleted_at' => now(), 'status' => false]);

        return response()->json(['success' => true]);
    }

    /** A picture for the catalog (logo, slide, group, step option): stored, path returned. */
    public function image(Request $request, int $id)
    {
        $this->can($request);
        $c = $this->catalog($request, $id);
        $request->validate(['image' => 'required|image|mimes:jpg,jpeg,png,webp|max:6144']);
        $path = CompanyFiles::putUpload((int) $c->company_id, 'portfolio', $request->file('image'));

        return response()->json(['path' => $path, 'url' => PortfolioCatalog::url($path)]);
    }

    public function storeGroup(Request $request, int $id)
    {
        $this->can($request);
        $c = $this->catalog($request, $id);
        $v = $request->validate(['name' => 'required|string|max:120']);
        $sort = (int) DB::table('portfolio_catalog_groups')->where('catalog_id', $c->id)->max('sort_order') + 1;
        DB::table('portfolio_catalog_groups')->insert(['company_id' => $c->company_id, 'catalog_id' => $c->id, 'name' => strip_tags($v['name']),
            'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    private function group(Request $request, int $gid): array
    {
        $g = DB::table('portfolio_catalog_groups')->where('id', $gid)->first();
        abort_unless($g, 404);

        return [$g, $this->catalog($request, (int) $g->catalog_id)];
    }

    public function updateGroup(Request $request, int $gid)
    {
        $this->can($request);
        [$g, $c] = $this->group($request, $gid);
        $v = $request->validate(['name' => 'required|string|max:120', 'image' => 'nullable|string|max:255']);
        $img = PortfolioCatalog::path($v['image'] ?? '');
        DB::table('portfolio_catalog_groups')->where('id', $g->id)->update(['name' => strip_tags($v['name']),
            'image' => $img !== '' && str_starts_with($img, 'files/company_'.$c->company_id.'/') ? $img : null, 'updated_at' => now()]);

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    public function destroyGroup(Request $request, int $gid)
    {
        $this->can($request);
        [$g, $c] = $this->group($request, $gid);
        DB::transaction(function () use ($g) {
            DB::table('portfolio_catalog_items')->where('group_id', $g->id)->delete();
            DB::table('portfolio_catalog_groups')->where('id', $g->id)->delete();
        });

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    public function reorderGroups(Request $request, int $id)
    {
        $this->can($request);
        $c = $this->catalog($request, $id);
        $ids = array_map('intval', (array) $request->input('ids', []));
        foreach ($ids as $i => $gid) {
            DB::table('portfolio_catalog_groups')->where('id', $gid)->where('catalog_id', $c->id)->update(['sort_order' => $i + 1]);
        }

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    public function addItems(Request $request, int $gid)
    {
        $this->can($request);
        [$g, $c] = $this->group($request, $gid);
        $v = $request->validate(['product_ids' => 'required|array|min:1|max:200', 'product_ids.*' => 'integer']);
        $valid = DB::table('products')->where('company_id', $c->company_id)->whereNull('deleted_at')->whereIn('id', $v['product_ids'])->pluck('id');
        $have = DB::table('portfolio_catalog_items')->where('group_id', $g->id)->pluck('product_id')->flip();
        $sort = (int) DB::table('portfolio_catalog_items')->where('group_id', $g->id)->max('sort_order');
        foreach ($valid as $pid) {
            if ($have->has($pid)) {
                continue;
            }
            DB::table('portfolio_catalog_items')->insert(['company_id' => $c->company_id, 'catalog_id' => $c->id, 'group_id' => $g->id,
                'product_id' => $pid, 'sort_order' => ++$sort, 'created_at' => now(), 'updated_at' => now()]);
        }

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    private function item(Request $request, int $iid): array
    {
        $i = DB::table('portfolio_catalog_items')->where('id', $iid)->first();
        abort_unless($i, 404);

        return [$i, $this->catalog($request, (int) $i->catalog_id)];
    }

    /** models: which step options the service fits ({step id: [option ids]}; empty = all). */
    public function updateItem(Request $request, int $iid)
    {
        $this->can($request);
        [$i, $c] = $this->item($request, $iid);
        $steps = PortfolioCatalog::settings(json_decode((string) $c->settings, true), $c->type)['steps'];
        $opts = [];
        foreach ($steps as $st) {
            $allowed = array_column($st['options'], 'id');
            $picked = array_values(array_intersect((array) ($request->input('options.'.$st['id']) ?? []), $allowed));
            if ($picked) {
                $opts[$st['id']] = $picked;
            }
        }
        DB::table('portfolio_catalog_items')->where('id', $i->id)->update(['options' => $opts ? json_encode($opts) : null, 'updated_at' => now()]);

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    /**
     * A model photo saved as a JPEG of at most 1600 px (camera photos are large):
     * turned upright from the camera's EXIF orientation, null if not a picture.
     */
    private function storePhoto(int $companyId, string $bytes, string $name): ?string
    {
        return CompanyFiles::putPhoto($companyId, 'portfolio', $bytes, $name);
    }

    private function addPhotos(object $i, object $c, array $paths): void
    {
        $list = array_merge(PortfolioCatalog::photos($i->photos, (int) $c->company_id), $paths);
        DB::table('portfolio_catalog_items')->where('id', $i->id)->update(['photos' => json_encode(PortfolioCatalog::photos($list, (int) $c->company_id)), 'updated_at' => now()]);
    }

    /** Model photos of an item: upload more or a camera shot (files "photos[]"), appended at the end. */
    public function uploadPhotos(Request $request, int $iid)
    {
        $this->can($request);
        [$i, $c] = $this->item($request, $iid);
        $request->validate(['photos' => 'required|array|min:1|max:30', 'photos.*' => 'image|mimes:jpg,jpeg,png,webp,heic,heif|max:20480']);
        $paths = [];
        foreach ($request->file('photos') as $f) {
            $p = $this->storePhoto((int) $c->company_id, (string) file_get_contents($f->getRealPath()), $f->getClientOriginalName());
            abort_unless($p, 422, 'This picture format cannot be read; use JPG, PNG or WebP.');
            $paths[] = $p;
        }
        $this->addPhotos($i, $c, $paths);

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    /**
     * Model photo from a web link ({url}): an image address, or a page whose
     * og:image is taken. Only public http(s) addresses (no local network), up to
     * 3 redirects, 15 MB.
     */
    public function photoFromUrl(Request $request, int $iid)
    {
        $this->can($request);
        [$i, $c] = $this->item($request, $iid);
        $url = $request->validate(['url' => 'required|url:http,https|max:2000'])['url'];
        $fetch = function (string $url) {
            for ($hop = 0; $hop < 4; $hop++) {
                $host = parse_url($url, PHP_URL_HOST);
                $ips = $host ? (gethostbynamel($host) ?: []) : [];
                $public = $ips && collect($ips)->every(fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
                abort_unless($public && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true), 422, 'This address cannot be used.');
                $res = \Illuminate\Support\Facades\Http::withOptions(['allow_redirects' => false])->timeout(20)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (Murano catalog)'])->get($url);
                if ($res->redirect() && $res->header('Location')) {
                    $url = (string) \GuzzleHttp\Psr7\UriResolver::resolve(new \GuzzleHttp\Psr7\Uri($url), new \GuzzleHttp\Psr7\Uri($res->header('Location')));

                    continue;
                }
                abort_unless($res->ok(), 422, 'The address did not answer (HTTP '.$res->status().').');
                abort_if(strlen($res->body()) > 15 * 1024 * 1024, 422, 'The picture is larger than 15 MB.');

                return [$url, $res];
            }
            abort(422, __('Too many redirects.'));
        };
        [$url, $res] = $fetch($url);
        if (str_contains(strtolower((string) $res->header('Content-Type')), 'text/html')
            && (preg_match('~<meta[^>]+(?:property|name)=["\']og:image["\'][^>]*content=["\']([^"\']+)~i', $res->body(), $m)
                || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']og:image["\']~i', $res->body(), $m))) {
            [$url, $res] = $fetch(html_entity_decode($m[1]));
        }
        $p = $this->storePhoto((int) $c->company_id, $res->body(), basename((string) parse_url($url, PHP_URL_PATH)));
        abort_unless($p, 422, 'No picture found at that address.');
        $this->addPhotos($i, $c, [$p]);

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    /** Model photos of an item: the new order / what is left after removing ({photos: [paths]}). */
    public function savePhotos(Request $request, int $iid)
    {
        $this->can($request);
        [$i, $c] = $this->item($request, $iid);
        $request->validate(['photos' => 'present|array|max:60', 'photos.*' => 'string|max:255']);
        $list = PortfolioCatalog::photos($request->input('photos', []), (int) $c->company_id);
        DB::table('portfolio_catalog_items')->where('id', $i->id)->update(['photos' => $list ? json_encode($list) : null, 'updated_at' => now()]);

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    public function destroyItem(Request $request, int $iid)
    {
        $this->can($request);
        [$i, $c] = $this->item($request, $iid);
        DB::table('portfolio_catalog_items')->where('id', $i->id)->delete();

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    public function reorderItems(Request $request, int $gid)
    {
        $this->can($request);
        [$g, $c] = $this->group($request, $gid);
        foreach (array_map('intval', (array) $request->input('ids', [])) as $n => $iid) {
            DB::table('portfolio_catalog_items')->where('id', $iid)->where('group_id', $g->id)->update(['sort_order' => $n + 1]);
        }

        return response()->json(['catalog' => $this->out($c, true)]);
    }

    public function products(Request $request, int $id)
    {
        $this->can($request);
        $c = $this->catalog($request, $id);
        $q = DB::table('products')->where('company_id', $c->company_id)->whereNull('deleted_at');
        if ($s = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('name', 'like', '%'.$s.'%')->orWhere('code', 'like', '%'.$s.'%'));
        }

        return response()->json(['products' => $q->orderBy('name')->limit(60)->get(['id', 'name', 'code', 'price', 'image', 'type'])
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'price' => (float) $p->price, 'type' => $p->type,
                'image_url' => PortfolioCatalog::productImage($p->image)])->values()]);
    }

    public function orders(Request $request, int $id)
    {
        $this->can($request, false);
        $c = $this->catalog($request, $id);
        $rows = DB::table('portfolio_catalog_orders')->where('catalog_id', $c->id)->orderByDesc('id')->limit(100)->get();

        return response()->json(['orders' => $rows->map(fn ($o) => (array) $o + ['lines' => json_decode((string) $o->lines, true) ?: []])->values()]);
    }

    /** Suggested type for the company (restaurant / food -> menu; salon / barber -> models). */
    public static function suggestedType(int $companyId): string
    {
        return in_array(BusinessTypes::of($companyId), ['salon', 'barber'], true) ? 'models' : 'menu';
    }
}
