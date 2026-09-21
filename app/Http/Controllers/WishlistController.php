<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class WishlistController extends Controller
{
    /**
     * Toggle a product in the current customer's wishlist.
     * Returns { in_wishlist: bool, count: int }.
     */
    public function toggle(Request $request)
    {
        $client = Auth::guard('store')->user();
        if (! $client) {
            return response()->json(['message' => __('messages.PleaseLoginToUseWishlist')], 401);
        }

        $data = $request->validate([
            'product_id' => 'required|integer|exists:products,id',
        ]);

        $existing = Wishlist::where('client_id', $client->id)
            ->where('product_id', $data['product_id'])
            ->first();

        if ($existing) {
            $existing->delete();
            $inWishlist = false;
        } else {
            Wishlist::create([
                'client_id' => $client->id,
                'product_id' => $data['product_id'],
            ]);
            $inWishlist = true;
        }

        return response()->json([
            'in_wishlist' => $inWishlist,
            'count' => Wishlist::where('client_id', $client->id)->count(),
        ]);
    }

    /**
     * The current customer's wishlisted product ids (empty for guests).
     * Lets storefront cards reflect saved state without touching every controller.
     */
    public function ids(Request $request)
    {
        $client = Auth::guard('store')->user();
        if (! $client) {
            return response()->json(['ids' => [], 'count' => 0]);
        }

        $ids = Wishlist::where('client_id', $client->id)->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        return response()->json(['ids' => $ids, 'count' => count($ids)]);
    }

    /**
     * Remove a product from the wishlist (used by the wishlist page).
     */
    public function remove(Request $request, $productId)
    {
        $client = Auth::guard('store')->user();
        if (! $client) {
            return response()->json(['message' => __('messages.PleaseLoginToUseWishlist')], 401);
        }

        Wishlist::where('client_id', $client->id)
            ->where('product_id', (int) $productId)
            ->delete();

        return response()->json([
            'ok' => true,
            'count' => Wishlist::where('client_id', $client->id)->count(),
        ]);
    }
}
