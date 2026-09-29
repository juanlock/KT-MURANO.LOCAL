<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Setting;
use App\Models\StorePage;

/**
 * The standard Store -> Pages set for a company that uses the DEFAULT online
 * store (no imported website): About Us, Contact Us, Shipping & Returns,
 * Privacy Policy and FAQ, filled with the company's own name/email/phone/
 * address. Only missing slugs are created -- existing pages are never
 * overwritten, so an admin's edits survive re-running it.
 */
class StorePageDefaults
{
    /** @return int number of pages created */
    public static function ensure(int $companyId): int
    {
        $company = Company::find($companyId);
        $setting = Setting::forCompany($companyId);
        $name = e($setting->CompanyName ?: ($company->name ?? 'Our company'));
        $email = e($setting->email ?: ($company->email ?? ''));
        $phone = e($setting->CompanyPhone ?: ($company->phone ?? ''));
        $address = e($setting->CompanyAdress ?: '');

        $contactLines = array_filter([
            $address ? "<p><strong>Address:</strong> $address</p>" : null,
            $phone ? "<p><strong>Phone:</strong> $phone</p>" : null,
            $email ? "<p><strong>Email:</strong> <a href=\"mailto:$email\">$email</a></p>" : null,
        ]);
        $contact = $contactLines ? implode('', $contactLines) : '<p>Get in touch with us any time.</p>';

        $pages = [
            'about-us' => ['About Us', "<h2>About $name</h2><p>Welcome to $name. We are committed to quality products and friendly, reliable service.</p><p>Thank you for choosing us.</p>"],
            'contact-us' => ['Contact Us', "<h2>Contact $name</h2>$contact"],
            'shipping-returns' => ['Shipping & Returns', "<h2>Shipping</h2><p>Orders are processed within 1–2 business days. Delivery times depend on your location.</p><h2>Returns</h2><p>Unused items in their original condition can be returned within 30 days of delivery. Contact us to start a return.</p>"],
            'privacy-policy' => ['Privacy Policy', "<h2>Privacy Policy</h2><p>$name collects only the information needed to process your orders and improve our service. We never sell your personal data.</p><p>Questions? $contact</p>"],
            'faq' => ['FAQ', "<h2>Frequently Asked Questions</h2><h3>How can I pay?</h3><p>We accept the payment methods shown at checkout.</p><h3>How do I track my order?</h3><p>Sign in to your account to see your order status.</p><h3>How do I contact you?</h3>$contact"],
        ];

        $created = 0;
        foreach ($pages as $slug => [$title, $html]) {
            if (StorePage::where('company_id', $companyId)->where('slug', $slug)->exists()) {
                continue;
            }
            StorePage::create([
                'company_id' => $companyId,
                'title' => $title,
                'slug' => $slug,
                'content' => $html,
                'seo_title' => $title.' — '.html_entity_decode($name),
                'published' => true,
            ]);
            $created++;
        }

        return $created;
    }
}
