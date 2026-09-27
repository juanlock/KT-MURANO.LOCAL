<?php

namespace App\Http\Controllers;

use App\Models\Country;
use App\Models\UsCity;
use Illuminate\Http\Request;

/**
 * Read-only reference data (countries.xlsx / uscities.xlsx, imported via
 * `php artisan geo:import`) behind Quick Add Customer's Country/City
 * dropdowns. Small enough (249 rows) to load in full for Country; uscities
 * (31k+ rows) is always searched server-side, never loaded whole.
 */
class GeoDataController extends Controller
{
    public function countries()
    {
        $countries = Country::orderBy('name')->get(['id', 'name', 'iso2', 'calling_code']);

        return response()->json(['countries' => $countries]);
    }

    public function searchUsCities(Request $request)
    {
        $term = trim((string) $request->query('q', ''));

        $query = UsCity::query();
        if ($term !== '') {
            $query->where(function ($q) use ($term) {
                $q->where('city', 'like', "{$term}%")
                    ->orWhere('city_ascii', 'like', "{$term}%");
            });
        }

        $cities = $query->orderByDesc('population')
            ->limit(20)
            ->get(['id', 'city', 'state_id', 'state_name']);

        return response()->json(['cities' => $cities]);
    }
}
