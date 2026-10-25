<?php

namespace App\Support;

use App\Models\HelpdeskSetting;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Companies -> Add / Edit (2026-10-12): everything the system needs from a
 * company, in one form and saved in one transaction to where each part lives:
 *
 *  - companies: name, owner_name, business_type, email, phone, country
 *  - settings:  CompanyName / email / CompanyPhone (same values), CompanyAdress,
 *               currency_id, default_language, sale_prefix, public_base_url,
 *               default_password_employee / _customer (hashed, write-only)
 *  - helpdesk_settings: from_email (the support mailbox), from_name
 *
 * REQUIRED = what a company must have to work (invoices, emails, helpdesk);
 * missing() lists what an existing company still lacks (Companies list).
 */
class CompanyProfile
{
    public const REQUIRED = [
        'name' => 'Company name',
        'business_type' => 'Business type',
        'owner_name' => 'Owner name',
        'email' => 'Company email',
        'phone' => 'Phone',
        'address' => 'Address',
        'helpdesk_email' => 'Helpdesk email',
    ];

    public static function get(int $companyId): array
    {
        $c = DB::table('companies')->where('id', $companyId)->first();
        $s = DB::table('settings')->where('company_id', $companyId)->whereNull('deleted_at')->first();
        $h = Schema::hasTable('helpdesk_settings') ? DB::table('helpdesk_settings')->where('company_id', $companyId)->first() : null;

        return [
            'name' => $c->name ?? null,
            'owner_name' => $c->owner_name ?? null,
            'business_type' => $c->business_type ?? null,
            'email' => $c->email ?? null,
            'phone' => $c->phone ?? null,
            'country' => $c->country ?? null,
            'address' => $s->CompanyAdress ?? null,
            'currency_id' => isset($s->currency_id) ? (int) $s->currency_id : null,
            'default_language' => $s->default_language ?? 'en',
            'sale_prefix' => $s->sale_prefix ?? null,
            'public_base_url' => $s->public_base_url ?? null,
            'default_password_employee_set' => ! empty($s->default_password_employee ?? null),
            'default_password_customer_set' => ! empty($s->default_password_customer ?? null),
            'helpdesk_email' => $h->from_email ?? null,
            'helpdesk_name' => $h->from_name ?? null,
        ];
    }

    /** Labels of the required fields the company still lacks. */
    public static function missing(int $companyId): array
    {
        $p = self::get($companyId);

        return array_values(array_map(fn ($k) => self::REQUIRED[$k],
            array_filter(array_keys(self::REQUIRED), fn ($k) => trim((string) ($p[$k] ?? '')) === '')));
    }

    /** Validation rules for the profile fields (create and edit). */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'owner_name' => ['required', 'string', 'max:190'],
            'business_type' => ['required', Rule::in(array_keys(BusinessTypes::LABELS))],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['required', 'string', 'max:50'],
            'country' => ['nullable', 'string', 'max:100'],
            'address' => ['required', 'string', 'max:255'],
            'helpdesk_email' => ['required', 'email', 'max:190'],
            'helpdesk_name' => ['nullable', 'string', 'max:190'],
            'currency_id' => ['required', 'integer', Rule::exists('currencies', 'id')->whereNull('deleted_at')],
            'default_language' => ['required', 'string', Rule::exists('languages', 'locale')],
            'sale_prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Za-z0-9_\-]+$/'],
            'public_base_url' => ['nullable', 'url', 'max:255'],
            'default_password_employee_new' => ['nullable', 'string', 'min:6', 'max:100'],
            'default_password_customer_new' => ['nullable', 'string', 'min:6', 'max:100'],
        ];
    }

    public static function messages(): array
    {
        return [
            'sale_prefix.regex' => 'The sale prefix may only use letters, numbers, - and _ (e.g. INV or PP).',
            'default_password_employee_new.min' => 'Default passwords need at least 6 characters.',
            'default_password_customer_new.min' => 'Default passwords need at least 6 characters.',
        ];
    }

    /** Saves the validated profile, all or nothing. */
    public static function save(int $companyId, array $d): void
    {
        DB::transaction(function () use ($companyId, $d) {
            $company = array_intersect_key($d, array_flip(['name', 'owner_name', 'email', 'phone', 'country']));
            if (array_key_exists('business_type', $d)) {
                $company['business_type'] = BusinessTypes::valid($d['business_type']);
            }
            DB::table('companies')->where('id', $companyId)->update($company + ['updated_at' => now()]);
            if (! empty($company['business_type'])) {
                // The type's own accounts / expense categories (real setup, idempotent).
                BusinessTypes::ensure($companyId, $company['business_type']);
            }

            $s = Setting::forCompany($companyId); // makes the row when missing
            $set = [];
            foreach (['name' => 'CompanyName', 'email' => 'email', 'phone' => 'CompanyPhone', 'address' => 'CompanyAdress',
                'currency_id' => 'currency_id', 'default_language' => 'default_language', 'sale_prefix' => 'sale_prefix'] as $in => $col) {
                if (array_key_exists($in, $d)) {
                    $set[$col] = $d[$in];
                }
            }
            // Dates follow the country: Peru day-month-year, USA month-day-year (2026-10-25).
            if (array_key_exists('country', $company) && ($fmt = DateFormats::forCountry($company['country']))) {
                $set['date_format'] = $fmt;
            }
            if (array_key_exists('public_base_url', $d)) {
                $set['public_base_url'] = $d['public_base_url'] ? rtrim(trim($d['public_base_url']), '/') : null;
            }
            foreach (['employee', 'customer'] as $t) {
                if (! empty($d["default_password_{$t}_new"])) {
                    $set["default_password_{$t}"] = Hash::make((string) $d["default_password_{$t}_new"]);
                }
            }
            if ($set) {
                DB::table('settings')->where('id', $s->id)->update($set + ['updated_at' => now()]);
            }

            $help = [];
            if (array_key_exists('helpdesk_email', $d)) {
                $help['from_email'] = $d['helpdesk_email'];
            }
            if (array_key_exists('helpdesk_name', $d)) {
                $help['from_name'] = $d['helpdesk_name'] ?: null;
            }
            if ($help && Schema::hasTable('helpdesk_settings')) {
                $h = HelpdeskSetting::forCompany($companyId); // makes the row when missing
                DB::table('helpdesk_settings')->where('id', $h->id)->update($help + ['updated_at' => now()]);
            }
        });
        CompanyDomain::forget();
    }

    /** Dropdown data for the form (business types + the modules each starts with, currencies, active languages). */
    public static function options(): array
    {
        return [
            'business_types' => collect(BusinessTypes::LABELS)
                ->map(fn ($label, $value) => ['value' => $value, 'label' => $label, 'modules' => BusinessTypes::modules($value)])->values(),
            'currencies' => DB::table('currencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'code', 'name', 'symbol']),
            'languages' => DB::table('languages')->where('is_active', 1)->orderBy('name')->get(['locale', 'name']),
            // Companies -> Add starts with the AdminSystem (template company 999) language / currency.
            'template_defaults' => (function () {
                $t = DB::table('settings')->where('company_id', Setting::TEMPLATE_COMPANY_ID)->whereNull('deleted_at')->first(['default_language', 'currency_id']);

                return ['default_language' => $t->default_language ?? 'en', 'currency_id' => isset($t->currency_id) ? (int) $t->currency_id : null];
            })(),
        ];
    }
}
