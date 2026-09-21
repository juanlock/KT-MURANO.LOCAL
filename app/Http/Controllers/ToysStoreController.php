<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\StoreSetting;
use App\Services\FlashSaleService;
use App\Support\ToysTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Toys & Baby storefront theme — homepage. Active when StoreSetting->theme
 * === 'toys'. Reuses the Electronics controller's product hydration so cards
 * carry the same prices, stock and ratings as the shop page.
 */
class ToysStoreController extends ElectronicsStoreController
{
    public function home(Request $request)
    {
        $s = StoreSetting::firstOrFail();
        $opts = ToysTheme::options($s);
        $sections = $opts['sections'];
        $limits = $opts['limits'];
        $defaultTaxRate = (float) ($s->default_tax_rate ?? 0);

        $slides = collect($opts['slides'])->map(function ($row) {
            $row['image_url'] = ToysTheme::imageUrl($row['image'] ?? '');
            $row['primary_href'] = ToysTheme::link($row['primary_url'] ?? '/shop');
            $row['secondary_href'] = ToysTheme::link($row['secondary_url'] ?? '/shop?deals=1');

            return $row;
        })->values();

        $categories = Category::with('subcategories')->orderBy('name')->get();

        // Category circles: icon + pastel colour, only categories with products.
        $categoryTiles = collect();
        if ($sections['categories']) {
            $counts = Product::query()
                ->where('is_active', 1)
                ->where('hide_from_online_store', 0)
                ->select('category_id', DB::raw('COUNT(*) AS c'))
                ->groupBy('category_id')
                ->pluck('c', 'category_id');

            $categoryTiles = $categories
                ->filter(fn ($c) => (int) ($counts[$c->id] ?? 0) > 0)
                ->values()
                ->map(fn ($c, $i) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'icon' => $c->icon ?: ToysTheme::guessIcon($c->name),
                    'count' => (int) ($counts[$c->id] ?? 0),
                    'pastel' => ToysTheme::pastel($i),
                    'url' => route('store.shop', ['category' => $c->id]),
                ])
                ->take($limits['categories'] - 1)
                ->values();
        }

        $tiles = collect($opts['tiles'])->map(function ($row) {
            $row['image_url'] = ToysTheme::imageUrl($row['image'] ?? '');
            $row['href'] = ToysTheme::link($row['url'] ?? '/shop');
            $row['pastel'] = ToysTheme::pastel($row['color'] ?? 0);

            return $row;
        })->values();

        // Popular picks: online best sellers, then featured / most reviewed.
        $popular = collect();
        if ($sections['popular']) {
            [$minVariantSub, $baseExpr, $afterDiscountExpr, $finalExpr] = $this->priceSqlParts();
            $popular = $this->buildBestSellerProducts($s, $baseExpr, $afterDiscountExpr, $finalExpr, $minVariantSub, $defaultTaxRate, $limits['popular']);
            if ($popular->count() < $limits['popular']) {
                $have = $popular->pluck('id')->all();
                $ratingSub = $this->ratingSubquery();
                $q = $this->baseQuery()->when($have, fn ($q) => $q->whereNotIn('products.id', $have));
                if ($ratingSub) {
                    $q->leftJoinSub($ratingSub, 'pr', fn ($j) => $j->on('pr.product_id', '=', 'products.id'))
                        ->orderByDesc('products.is_featured')
                        ->orderByDesc('pr.rating_count')
                        ->orderByDesc('pr.rating_avg');
                } else {
                    $q->orderByDesc('products.is_featured');
                }
                $more = $this->hydrate($q->orderByDesc('products.created_at'), $s, $defaultTaxRate, $limits['popular'] - $popular->count());
                $popular = $popular->concat($more)->values();
            }
            $this->attachRatings($popular);
        }

        $newArrivals = $sections['new_arrivals']
            ? $this->hydrate($this->baseQuery()->orderByDesc('products.created_at')->orderByDesc('products.id'), $s, $defaultTaxRate, $limits['new_arrivals'])
            : collect();

        $deals = collect();
        if ($sections['deals']) {
            $flashIds = array_keys(app(FlashSaleService::class)->runningRules());
            $deals = $this->hydrate(
                $this->baseQuery()
                    ->where(function ($q) use ($flashIds) {
                        $q->where('products.discount', '>', 0);
                        if ($flashIds) {
                            $q->orWhereIn('products.id', $flashIds);
                        }
                    })
                    ->orderByDesc('products.discount'),
                $s, $defaultTaxRate, $limits['deals']
            );
        }

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

        return view('store.toys.home', [
            's' => $s,
            'opts' => $opts,
            'sections' => $sections,
            'slides' => $slides,
            'categories' => $categories,
            'categoryTiles' => $categoryTiles,
            'tiles' => $tiles,
            'popular' => $popular,
            'newArrivals' => $newArrivals,
            'deals' => $deals,
            'testimonials' => $testimonials,
            'showCategoryBar' => false,
        ]);
    }
}
