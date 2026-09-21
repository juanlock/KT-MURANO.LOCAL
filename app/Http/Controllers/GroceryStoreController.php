<?php

namespace App\Http\Controllers;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\StoreSetting;
use App\Services\FlashSaleService;
use App\Support\GroceryTheme;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Grocery & Supermarket storefront theme — homepage. Active when
 * StoreSetting->theme === 'grocery'. Reuses the shared product pipeline in
 * StoreFrontController (prices, stock, ratings) so cards match the shop page.
 */
class GroceryStoreController extends ElectronicsStoreController
{
    /** Cards show the sale unit ("kg", "pack"), so eager-load it. */
    protected function baseQuery()
    {
        return parent::baseQuery()->with('unitSale:id,name,ShortName');
    }

    public function home(Request $request)
    {
        $s = StoreSetting::firstOrFail();
        $opts = GroceryTheme::options($s);
        $sections = $opts['sections'];
        $limits = $opts['limits'];
        $defaultTaxRate = (float) ($s->default_tax_rate ?? 0);

        $slides = collect($opts['slides'])->map(function ($row) {
            $row['image_url'] = GroceryTheme::imageUrl($row['image'] ?? '');
            $row['primary_href'] = GroceryTheme::link($row['primary_url'] ?? '/shop');
            $row['secondary_href'] = GroceryTheme::link($row['secondary_url'] ?? '/shop?deals=1');

            return $row;
        })->values();

        $categories = Category::with('subcategories')->orderBy('name')->get();

        $counts = Product::query()
            ->where('is_active', 1)
            ->where('hide_from_online_store', 0)
            ->select('category_id', DB::raw('COUNT(*) AS c'))
            ->groupBy('category_id')
            ->pluck('c', 'category_id');

        $categoryTiles = $categories
            ->filter(fn ($c) => (int) ($counts[$c->id] ?? 0) > 0)
            ->map(function ($c) use ($counts) {
                $img = Product::query()
                    ->where('category_id', $c->id)->where('is_active', 1)->where('hide_from_online_store', 0)
                    ->whereNotNull('image')->where('image', '!=', '')->where('image', '!=', 'no-image.png')
                    ->orderByDesc('is_featured')->orderByDesc('created_at')->value('image');

                return [
                    'id' => $c->id,
                    'name' => $c->name,
                    'icon' => $c->icon ?: GroceryTheme::guessIcon($c->name),
                    'count' => (int) ($counts[$c->id] ?? 0),
                    'image_url' => $img ? product_image_url($img) : null,
                    'url' => route('store.shop', ['category' => $c->id]),
                ];
            })
            ->sortByDesc('count')
            ->values();

        // Weekly deals: discounted + flash-sale products.
        $deals = collect();
        if ($sections['deals']) {
            $flashIds = array_keys(app(FlashSaleService::class)->runningRules());
            $deals = $this->hydrate(
                $this->baseQuery()->where(function ($q) use ($flashIds) {
                    $q->where('products.discount', '>', 0);
                    if ($flashIds) {
                        $q->orWhereIn('products.id', $flashIds);
                    }
                })->orderByDesc('products.discount')->orderByDesc('products.created_at'),
                $s, $defaultTaxRate, $limits['deals']
            );
        }

        // Popular: online best sellers, then featured / most reviewed.
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
                        ->orderByDesc('products.is_featured')->orderByDesc('pr.rating_count')->orderByDesc('pr.rating_avg');
                } else {
                    $q->orderByDesc('products.is_featured');
                }
                $more = $this->hydrate($q->orderByDesc('products.created_at'), $s, $defaultTaxRate, $limits['popular'] - $popular->count());
                $popular = $popular->concat($more)->values();
            }
            $this->attachRatings($popular);
        }

        // Aisles: the biggest categories, each with a row of products.
        $aisles = collect();
        if ($sections['aisles']) {
            $aisles = $categoryTiles->take($limits['aisles'])->map(function ($tile) use ($s, $defaultTaxRate, $limits) {
                $tile['products'] = $this->hydrate(
                    $this->baseQuery()->where('products.category_id', $tile['id'])->orderByDesc('products.is_featured')->orderByDesc('products.created_at'),
                    $s, $defaultTaxRate, $limits['aisle_products']
                );

                return $tile;
            })->filter(fn ($a) => $a['products']->isNotEmpty())->values();
        }

        // Buy again: what the signed-in customer ordered before.
        $buyAgain = collect();
        $client = Auth::guard('store')->user();
        if ($sections['buy_again'] && $client && Schema::hasTable('online_order_items')) {
            $clientId = $client->client_id ?? $client->id;
            $ids = DB::table('online_order_items')
                ->join('online_orders', 'online_orders.id', '=', 'online_order_items.order_id')
                ->where('online_orders.client_id', $clientId)
                ->whereNotIn('online_orders.status', ['cancelled', 'canceled'])
                ->whereNotNull('online_order_items.product_id')
                ->orderByDesc('online_orders.created_at')
                ->limit(40)
                ->pluck('online_order_items.product_id')
                ->unique()
                ->take(6)
                ->values()
                ->all();
            if ($ids) {
                $buyAgain = $this->hydrate($this->baseQuery()->whereIn('products.id', $ids), $s, $defaultTaxRate, 6);
            }
        }

        $tiles = collect($opts['tiles'])->map(function ($row) {
            $row['image_url'] = GroceryTheme::imageUrl($row['image'] ?? '');
            $row['href'] = GroceryTheme::link($row['url'] ?? '/shop');
            $row['colors'] = GroceryTheme::tone($row['tone'] ?? 'green');

            return $row;
        })->values();

        $brands = collect();
        if ($sections['brands']) {
            $brandIds = Product::where('is_active', 1)->where('hide_from_online_store', 0)->whereNotNull('brand_id')->distinct()->pluck('brand_id');
            $brands = $brandIds->count() >= 3 ? Brand::whereIn('id', $brandIds)->orderBy('name')->take(12)->get() : collect();
        }

        return view('store.grocery.home', [
            's' => $s,
            'opts' => $opts,
            'sections' => $sections,
            'slides' => $slides,
            'categories' => $categories,
            'categoryTiles' => $categoryTiles,
            'deals' => $deals,
            'popular' => $popular,
            'aisles' => $aisles,
            'buyAgain' => $buyAgain,
            'tiles' => $tiles,
            'brands' => $brands,
            'showCategoryBar' => false,
        ]);
    }
}
