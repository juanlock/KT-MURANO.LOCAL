<?php

namespace App\utils;

use App\Models\Currency;
use App\Models\Role;
use App\Models\Setting;
use Illuminate\Support\Facades\Auth;

class helpers
{
    //  Helper Multiple Filter
    public function filter($model, $columns, $param, $request)
    {
        // Loop through the fields checking if they've been input, if they have add
        //  them to the query.
        $fields = [];
        for ($key = 0; $key < count($columns); $key++) {
            $fields[$key]['param'] = $param[$key];
            $fields[$key]['value'] = $columns[$key];
        }

        foreach ($fields as $field) {
            $model->where(function ($query) use ($request, $field, $model) {
                return $model->when($request->filled($field['value']),
                    function ($query) use ($request, $model, $field) {
                        $field['param'] = 'like' ?
                        $model->where($field['value'], 'like', "{$request[$field['value']]}")
                        : $model->where($field['value'], $request[$field['value']]);
                    });
            });
        }

        // Finally return the model
        return $model;
    }

    //  Check If Hass Permission Show All records
    public function Show_Records($model)
    {
        $user = Auth::user();
        
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $ShowRecord = false;
        
        if (isset($user->record_view)) {
            // Use user-level record_view field
            $ShowRecord = (bool) $user->record_view;
        } else {
            // Fallback to role permission check for backward compatibility
            $Role = $user->roles()->first();
            if ($Role) {
                $ShowRecord = Role::findOrFail($Role->id)->inRole('record_view');
            }
        }

        if (! $ShowRecord) {
            return $model->where('user_id', '=', Auth::user()->id);
        }

        return $model;
    }
    
    //  Check If User Has Record View Permission (with backward compatibility)
    public function HasRecordView($user = null)
    {
        if ($user === null) {
            $user = Auth::user();
        }
        
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        if (isset($user->record_view)) {
            // Use user-level record_view field
            return (bool) $user->record_view;
        } else {
            // Fallback to role permission check for backward compatibility
            $Role = $user->roles()->first();
            if ($Role) {
                return Role::findOrFail($Role->id)->inRole('record_view');
            }
        }
        
        return false;
    }

    // Get Currency
    public function Get_Currency()
    {
        $settings = Setting::with('Currency')->where('deleted_at', '=', null)->first();

        if ($settings && $settings->currency_id) {
            if (Currency::where('id', $settings->currency_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $symbol = $settings['Currency']->symbol;
            } else {
                $symbol = '';
            }
        } else {
            $symbol = '';
        }

        return $symbol;
    }

    // Get Currency COde
    public function Get_Currency_Code()
    {
        $settings = Setting::with('Currency')->where('deleted_at', '=', null)->first();

        if ($settings && $settings->currency_id) {
            if (Currency::where('id', $settings->currency_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $code = $settings['Currency']->code;
            } else {
                $code = 'usd';
            }
        } else {
            $code = 'usd';
        }

        return $code;
    }

    /**
     * Whether the Multi-Currency module is enabled. Read once per request and
     * cached statically like price_decimals().
     */
    public static function multi_currency_enabled()
    {
        static $enabled = null;

        if ($enabled === null) {
            try {
                $enabled = (bool) Setting::where('deleted_at', '=', null)
                    ->value('enable_multi_currency');
            } catch (\Throwable $e) {
                // Settings table/column may not exist yet (e.g. during migration) — fall back.
                $enabled = false;
            }
        }

        return $enabled;
    }

    /**
     * Resolve the currency a document was recorded in.
     *
     * Returns ['id','symbol','code','rate'] where rate = units of the document
     * currency per 1 base-currency unit (base rate is always 1). Falls back to
     * the global default currency with rate 1 when the document has no
     * currency_id, the currency row was deleted, or Multi-Currency is off —
     * so legacy rows and toggle-off installs behave exactly as before.
     *
     * @param  object|null  $doc  any model/stdClass with currency_id / exchange_rate
     */
    public static function Get_Document_Currency($doc)
    {
        $helpers = new self;
        $default = [
            'id' => null,
            'symbol' => $helpers->Get_Currency(),
            'code' => $helpers->Get_Currency_Code(),
            'rate' => 1.0,
        ];

        if (! $doc || ! self::multi_currency_enabled()) {
            return $default;
        }

        $currencyId = $doc->currency_id ?? null;
        if (! $currencyId) {
            return $default;
        }

        $currency = Currency::where('id', $currencyId)
            ->where('deleted_at', '=', null)
            ->first();
        if (! $currency) {
            return $default;
        }

        $rate = (float) ($doc->exchange_rate ?? 0);
        if ($rate <= 0) {
            $rate = (float) ($currency->exchange_rate ?? 1) ?: 1.0;
        }

        return [
            'id' => $currency->id,
            'symbol' => $currency->symbol,
            'code' => $currency->code,
            'rate' => $rate,
        ];
    }

    /**
     * Convert a base-currency amount to the document currency.
     */
    public static function to_document_amount($baseAmount, $rate)
    {
        return (float) $baseAmount * ((float) $rate ?: 1.0);
    }

    /**
     * Sanitize the currency snapshot sent by a document form.
     *
     * Returns ['currency_id','exchange_rate'] ready to persist. Base-currency
     * documents are stored as NULL/NULL (the backward-compat convention), a
     * missing field keeps the existing stored pair (so non-form API callers
     * don't clear it), and the client-sent rate is never trusted blindly —
     * anything <= 0 falls back to the currency's configured rate.
     */
    public static function resolve_request_currency($request, $existingCurrencyId = null, $existingRate = null)
    {
        if (! self::multi_currency_enabled() || ! $request->has('currency_id')) {
            return ['currency_id' => $existingCurrencyId, 'exchange_rate' => $existingRate];
        }

        // Permission gate (SalePolicy::multi_currency): the pickers are hidden
        // for users without it, so also refuse a crafted request here — the
        // stored pair is left untouched. No auth context (queued jobs) passes.
        try {
            $user = Auth::user();
            if ($user && $user->cannot('multi_currency', \App\Models\Sale::class)) {
                return ['currency_id' => $existingCurrencyId, 'exchange_rate' => $existingRate];
            }
        } catch (\Throwable $e) {
            // Permission lookup unavailable — never block the save itself.
        }

        $currencyId = $request->input('currency_id');
        if (empty($currencyId)) {
            return ['currency_id' => null, 'exchange_rate' => null];
        }

        $default = Setting::where('deleted_at', '=', null)->value('currency_id');
        if ((int) $currencyId === (int) $default) {
            return ['currency_id' => null, 'exchange_rate' => null];
        }

        $currency = Currency::where('id', $currencyId)
            ->where('deleted_at', '=', null)
            ->first();
        if (! $currency) {
            return ['currency_id' => null, 'exchange_rate' => null];
        }

        $rate = (float) $request->input('exchange_rate', 0);
        if ($rate <= 0) {
            $rate = (float) ($currency->exchange_rate ?? 1) ?: 1.0;
        }

        return ['currency_id' => $currency->id, 'exchange_rate' => $rate];
    }

    /**
     * Number of decimal places to use for monetary values.
     *
     * Driven by the "Enable 3 Decimal Pricing" setting: returns 3 when enabled,
     * 2 otherwise. The setting is read once per request and cached statically so
     * the many formatting call sites don't each hit the database.
     *
     * @return int 2 or 3
     */
    public static function price_decimals()
    {
        static $decimals = null;

        if ($decimals === null) {
            try {
                $enabled = (bool) Setting::where('deleted_at', '=', null)
                    ->value('enable_3_decimal_pricing');
                $decimals = $enabled ? 3 : 2;
            } catch (\Throwable $e) {
                // Settings table/column may not exist yet (e.g. during migration) — fall back.
                $decimals = 2;
            }
        }

        return $decimals;
    }

    /**
     * Apply the configured product-image downscale to an Intervention image.
     *
     * Driven by the "Resize Product Images" setting: when it is off (or the max
     * size is 0) the image is written at its original dimensions. Otherwise it is
     * constrained to a max_size x max_size box, keeping aspect ratio and never
     * upscaling — the behaviour that used to be hard-coded to 800x800 at every
     * upload site. Read once per request and cached statically.
     *
     * @param  \Intervention\Image\Image  $image
     * @return \Intervention\Image\Image  the same instance, for chaining ->save()
     */
    public static function apply_product_image_resize($image)
    {
        static $max = null;

        if ($max === null) {
            try {
                $setting = Setting::where('deleted_at', '=', null)
                    ->first(['product_image_resize', 'product_image_max_size']);
                // Never saved / column missing => keep the historical 800x800.
                $enabled = $setting === null ? true : (bool) ($setting->product_image_resize ?? true);
                $max = $enabled ? (int) ($setting->product_image_max_size ?? 800) : 0;
            } catch (\Throwable $e) {
                // Settings table/column may not exist yet (e.g. during migration).
                $max = 800;
            }
        }

        if ($max > 0) {
            $image->resize($max, $max, function ($constraint) {
                $constraint->aspectRatio();
                $constraint->upsize();
            });
        }

        return $image;
    }

    /**
     * Format price for display based on price_format setting
     *
     * @param float $number The number to format
     * @param int|null $decimals Number of decimal places (default: the configured price precision, 2 or 3)
     * @param string|null $priceFormat The price format key ('comma_dot', 'dot_comma', 'space_comma', or null for default)
     * @return string Formatted price string
     */
    public function formatPriceDisplay($number, $decimals = null, $priceFormat = null)
    {
        $number = (float) $number;
        $decimals = $decimals === null ? self::price_decimals() : (int) $decimals;
        
        // If no price format specified, use default number_format
        if (empty($priceFormat)) {
            return number_format($number, $decimals, '.', ',');
        }
        
        // Format based on price_format setting
        switch ($priceFormat) {
            case 'comma_dot':
                // 1,234.56 (thousand , decimal .)
                return number_format($number, $decimals, '.', ',');
                
            case 'dot_comma':
                // 1.234,56 (thousand . decimal ,)
                return number_format($number, $decimals, ',', '.');
                
            case 'space_comma':
                // 1 234,56 (thousand space, decimal ,)
                return number_format($number, $decimals, ',', ' ');
                
            default:
                // Fallback to default format
                return number_format($number, $decimals, '.', ',');
        }
    }
}
