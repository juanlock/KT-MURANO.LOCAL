<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\StoreSetting;
use App\Services\FlashSaleService;
use App\Support\ElectronicsTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Electronics storefront theme — homepage.
 *
 * Active when StoreSetting->theme === 'electronics'. Every other storefront
 * page (shop, product, cart, checkout, account…) keeps its controller and
 * view; the shared layout swaps its header/footer/skin for this theme, so the
 * whole store stays consistent. This controller only assembles the homepage
 * sections, reusing the parent's pricing/stock pipeline so prices match the
 * shop page to the cent.
 */
class ElectronicsStoreController extends StoreFrontController
{
    public function home(Request $request)
    {
        $s = StoreSetting::firstOrFail();
        $opts = ElectronicsTheme::options($s);
        $sections = $opts['sections'];
        $limits = $opts['limits'];
        $defaultTaxRate = (float) ($s->default_tax_rate ?? 0);

        // ----- Hero slides -----
        $slides = collect($opts['slides'])->map(function ($row) {
            $row['image_url'] = ElectronicsTheme::imageUrl($row['image'] ?? '');
            $row['primary_href'] = ElectronicsTheme::link($row['primary_url'] ?? '/shop');
            $row['secondary_href'] = ElectronicsTheme::link($row['secondary_url'] ?? '/shop?deals=1');

            return $row;
        })->values();

        // ----- Categories (tile image = newest product photo in the category) -----
        $categories = Category::with('subcategories')->orderBy('name')->get();
        $categoryTiles = collect();
        if ($sections['categories']) {
            $counts = Product::query()
                ->where('is_active', 1)
                ->where('hide_from_online_store', 0)
                ->select('category_id', DB::raw('COUNT(*) AS c'))
                ->groupBy('category_id')
                ->pluck('c', 'category_id');

            $categoryTiles = $categories
                ->map(function ($c) use ($counts) {
                    $img = Product::query()
                        ->where('category_id', $c->id)
                        ->where('is_active', 1)
                        ->where('hide_from_online_store', 0)
                        ->whereNotNull('image')
                        ->where('image', '!=', '')
                        ->where('image', '!=', 'no-image.png')
                        ->orderByDesc('is_featured')
                        ->orderByDesc('created_at')
                        ->value('image');

                    return [
                        'id' => $c->id,
                        'name' => $c->name,
                        'icon' => $c->icon,
                        'count' => (int) ($counts[$c->id] ?? 0),
                        'image_url' => $img ? product_image_url($img) : null,
                        'url' => route('store.shop', ['category' => $c->id]),
                    ];
                })
                ->filter(fn ($t) => $t['count'] > 0)
                ->sortByDesc('count')
                ->take($limits['categories'])
                ->values();
        }

        // ----- Featured tabs -----
        $featured = ['top_picks' => collect(), 'best_rated' => collect(), 'on_sale' => collect()];
        if ($sections['featured']) {
            $n = $limits['featured'];

            $topPicks = $this->hydrate(
                $this->baseQuery()->orderByDesc('products.is_featured')->orderByDesc('products.created_at'),
                $s, $defaultTaxRate, $n
            );

            $ratingSub = $this->ratingSubquery();
            $bestRated = $ratingSub
                ? $this->hydrate(
                    $this->baseQuery()
                        ->joinSub($ratingSub, 'pr', fn ($j) => $j->on('pr.product_id', '=', 'products.id'))
                        ->orderByDesc('pr.rating_avg')
                        ->orderByDesc('pr.rating_count'),
                    $s, $defaultTaxRate, $n
                )
                : collect();

            $flashIds = array_keys(app(FlashSaleService::class)->runningRules());
            $onSale = $this->hydrate(
                $this->baseQuery()
                    ->where(function ($q) use ($flashIds) {
                        $q->where('products.discount', '>', 0);
                        if ($flashIds) {
                            $q->orWhereIn('products.id', $flashIds);
                        }
                    })
                    ->orderByDesc('products.discount')
                    ->orderByDesc('products.created_at'),
                $s, $defaultTaxRate, $n
            );

            $featured = ['top_picks' => $topPicks, 'best_rated' => $bestRated, 'on_sale' => $onSale];
        }

        // ----- Promo banners -----
        $banners = collect($opts['banners'])->map(function ($row) {
            $row['image_url'] = ElectronicsTheme::imageUrl($row['image'] ?? '');
            $row['href'] = ElectronicsTheme::link($row['url'] ?? '/shop');

            return $row;
        })->values();

        // ----- Best sellers (by online sales; newest featured as a fallback) -----
        $bestSellers = collect();
        if ($sections['best_sellers']) {
            [$minVariantSub, $baseExpr, $afterDiscountExpr, $finalExpr] = $this->priceSqlParts();
            $bestSellers = $this->buildBestSellerProducts($s, $baseExpr, $afterDiscountExpr, $finalExpr, $minVariantSub, $defaultTaxRate, $limits['best_sellers']);
            if ($bestSellers->count() < $limits['best_sellers']) {
                $have = $bestSellers->pluck('id')->all();
                $more = $this->hydrate(
                    $this->baseQuery()
                        ->when($have, fn ($q) => $q->whereNotIn('products.id', $have))
                        ->orderByDesc('products.is_featured')
                        ->orderByDesc('products.price'),
                    $s, $defaultTaxRate, $limits['best_sellers'] - $bestSellers->count()
                );
                $bestSellers = $bestSellers->concat($more)->values();
            }
            $this->attachRatings($bestSellers);
        }

        // ----- New arrivals -----
        $newArrivals = $sections['new_arrivals']
            ? $this->hydrate($this->baseQuery()->orderByDesc('products.created_at')->orderByDesc('products.id'), $s, $defaultTaxRate, $limits['new_arrivals'])
            : collect();

        // ----- Stats -----
        $numbers = [
            'products' => Product::where('is_active', 1)->where('hide_from_online_store', 0)->count(),
            'customers' => Client::count(),
            'rating' => Schema::hasTable('product_reviews')
                ? (float) (ProductReview::where('status', 'approved')->avg('rating') ?: 4.9)
                : 4.9,
        ];
        $stats = collect($opts['stats'])->map(function ($row) use ($numbers) {
            $row['value'] = ElectronicsTheme::fillStat((string) ($row['value'] ?? ''), $numbers);

            return $row;
        })->values();

        // ----- Testimonials (approved 4★+ reviews with a comment) -----
        $testimonials = collect();
        if ($sections['testimonials'] && Schema::hasTable('product_reviews')) {
            $testimonials = ProductReview::query()
                ->with('product:id,name')
                ->where('status', 'approved')
                ->where('rating', '>=', 4)
                ->whereNotNull('comment')
                ->where('comment', '!=', '')
                ->orderByDesc('created_at')
                ->take(3)
                ->get();
        }

        // ----- Brands strip -----
        $brands = $sections['brands']
            ? Brand::query()->whereNull('deleted_at')->orderBy('name')->take(12)->get()
            : collect();

        return view('store.electronics.home', [
            's' => $s,
            'opts' => $opts,
            'sections' => $sections,
            'slides' => $slides,
            'categories' => $categories,
            'categoryTiles' => $categoryTiles,
            'featured' => $featured,
            'banners' => $banners,
            'bestSellers' => $bestSellers,
            'newArrivals' => $newArrivals,
            'stats' => $stats,
            'testimonials' => $testimonials,
            'brands' => $brands,
            'showCategoryBar' => false,
        ]);
    }
}
