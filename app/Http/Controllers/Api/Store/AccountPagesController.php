<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\EcommerceClient;
use App\Models\StoreSetting;
use App\Services\StoreCurrencyService;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AccountPagesController extends Controller
{
    public function account()
    {
        $s = StoreSetting::firstOrFail();

        $user = Auth::guard('store')->user();
        $client = $user && $user->client_id ? Client::find($user->client_id) : null;

        return view('store.account', compact('s', 'client'));
    }

    public function orders()
    {
        $s = StoreSetting::firstOrFail();

        return view('store.account_orders', compact('s'));
    }

    public function update(Request $request)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return redirect()->back()->withErrors(['auth' => 'You must be signed in.']);
        }

        $data = $request->validate([
            'username' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:ecommerce_clients,email,'.$user->id],
            'password' => ['nullable', 'confirmed', 'min:6'],
        ]);

        DB::transaction(function () use ($user, $data) {
            // Update EcommerceClient
            $user->username = $data['username'];
            $user->email = $data['email'];
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }
            $user->save();

            // Update linked Client
            if ($user->client_id) {
                $client = Client::find($user->client_id);
                if ($client) {
                    $client->name = $data['username']; // or $data['name'] if you had it
                    $client->email = $data['email'];
                    $client->save();
                }
            }
        });

        return redirect()
            ->back()
            ->with('status', __('messages.ProfileUpdated'));
    }

    /**
     * Save the customer's default storefront language / display currency.
     * Both are optional — blank means "use the store default".
     *
     * The saved value is also pushed into the session (and the language
     * cookie) so the change is visible immediately rather than only on the
     * next fresh session; clearing a preference drops the override so the
     * store default takes over right away.
     */
    public function updatePreferences(Request $request)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return redirect()->back()->withErrors(['auth' => 'You must be signed in.']);
        }

        $data = $request->validate([
            'preferred_locale' => ['nullable', 'string', Rule::in(array_keys(store_locales()))],
            'preferred_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
        ]);

        $locale = $data['preferred_locale'] ?? null;
        $currencyId = $data['preferred_currency_id'] ?? null;

        $user->preferred_locale = $locale;
        $user->preferred_currency_id = $currencyId;
        $user->save();

        if ($locale) {
            session(['locale' => $locale]);
            Cookie::queue('locale', $locale, 60 * 24 * 365, '/');
        } else {
            session()->forget('locale');
            Cookie::queue(Cookie::forget('locale', '/'));
        }

        if ($currencyId) {
            StoreCurrencyService::select($currencyId);
        } else {
            session()->forget('store_currency_id');
            StoreCurrencyService::flush();
        }

        return redirect()->back()->with('status', __('messages.PreferencesUpdated'));
    }

    /**
     * Update the customer's shipping/billing address (stored on the linked Client).
     * Storefront-created accounts use this to manage their address.
     */
    public function updateAddress(Request $request)
    {
        $user = Auth::guard('store')->user();
        if (! $user) {
            return redirect()->back()->withErrors(['auth' => 'You must be signed in.']);
        }
        if (! $user->client_id) {
            return redirect()->back()->withErrors(['address' => __('messages.CustomerProfileMissing')]);
        }

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'adresse' => ['nullable', 'string', 'max:250'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'zip' => ['nullable', 'string', 'max:30'],
            'country' => ['nullable', 'string', 'max:100'],
        ]);

        $client = Client::find($user->client_id);
        if ($client) {
            // Name is never blanked (it identifies the client elsewhere).
            if (! empty($data['name'])) {
                $client->name = $data['name'];
            }
            // Address fields may be explicitly cleared: assign whenever the
            // field was submitted (empty input arrives as null).
            foreach (['phone', 'adresse', 'city', 'state', 'zip', 'country'] as $field) {
                if ($request->exists($field)) {
                    $client->{$field} = $data[$field] ?? null;
                }
            }
            $client->save();
        }

        return redirect()->back()->with('status', __('messages.AddressUpdated'));
    }
}
