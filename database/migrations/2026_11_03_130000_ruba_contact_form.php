<?php

use App\Support\FormSchema;
use App\Support\PublicUrl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Ruba's "Contact Us" form in Murano (user's request, 2026-11-03): Ruba had no
 * form -- its imported website embedded the old Easy Forms form on kcoltech.com
 * (733DRw), so nothing reached Murano. This makes the form (the fields of the
 * site's original template: name, email, phone, subject, message; notices to
 * info@rubagardenlandscape.com) with the stable key "rubacontacto", and swaps
 * the "<!-- KcolTech --> ... <!-- End KcolTech -->" embed on the home page and
 * the Contact Us page for Murano's (the website is snapshotted first). Ruba is
 * live: this is how the change reaches production. Safe to run twice.
 */
return new class extends Migration
{
    private const KEY = 'rubacontacto';

    private const PAGES = ['index.html', '12_contact-us.html'];

    public function up(): void
    {
        $cid = (int) DB::table('companies')->where('name', 'like', 'Ruba Garden%')->whereNull('deleted_at')->value('id');
        if (! $cid) {
            return;
        }
        DB::transaction(fn () => $this->form($cid));
        $this->website($cid);
    }

    private function form(int $cid): void
    {
        if (DB::table('forms')->where('public_key', self::KEY)->exists()) {
            return;
        }
        $field = fn ($key, $type, $ph, $req, $extra = []) => array_merge(
            ['key' => $key, 'type' => $type, 'label' => '', 'width' => 'full', 'required' => $req, 'placeholder' => $ph, 'help' => '', 'default' => ''], $extra);
        $fields = [
            $field('name', 'text', 'Your name', true, ['max' => 255]),
            $field('email', 'email', 'Email', true),
            $field('phone', 'text', 'Phone #', false, ['max' => 50]),
            $field('subject', 'text', 'Subject', false, ['max' => 255]),
            $field('message', 'textarea', 'Message / Question', true, ['max' => 5000, 'rows' => 4]),
        ];
        $settings = array_merge(FormSchema::defaultSettings(), [
            'submit_text' => 'Send now', 'language' => 'en', 'confirmation' => 'message_redirect', 'redirect_seconds' => 5,
            'success_message' => 'Thank you! We received your message and will contact you soon.',
            'redirect_url' => 'https://www.rubagardenlandscape.com', 'hide_after_submit' => true,
            'notify' => true, 'notify_emails' => ['info@rubagardenlandscape.com', 'juanlock@kcoltech.com'],
            'notify_subject' => 'New submission: {form}', 'reply_to_field' => 'email',
            'honeypot' => true, 'min_seconds' => 3, 'active' => true, 'header_in_embed' => false,
        ]);
        $admin = DB::table('users')->where('company_id', $cid)->where('type', 'admin')->whereNull('deleted_at')->orderBy('id')->value('id');
        DB::table('forms')->insert([
            'company_id' => $cid, 'name' => 'Contact Us - rubagardenlandscape.com', 'public_key' => self::KEY, 'status' => 1,
            'fields' => json_encode($fields), 'settings' => json_encode($settings), 'next_number' => 1,
            'created_by' => $admin, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** The old embed -> Murano's, on each page that still has it. */
    private function website(int $cid): void
    {
        $dir = public_path('files/company_'.$cid.'/website/sites/rubagarden');
        $pattern = '/<!--\s*KcolTech\s*-->.*?<!--\s*End KcolTech\s*-->/s';
        $todo = array_filter(self::PAGES, fn ($p) => is_file("$dir/$p") && preg_match($pattern, (string) file_get_contents("$dir/$p")));
        if (! $todo) {
            return;
        }
        \App\Services\WebsiteSnapshots::take($cid);
        $embed = "<!-- Murano form -->\n<div data-murano-form=\"".self::KEY."\"></div>\n"
            .'<script src="'.PublicUrl::base($cid)."/embed/murano-forms.js\" async></script>\n<!-- End Murano form -->";
        foreach ($todo as $p) {
            $html = (string) file_get_contents("$dir/$p");
            file_put_contents("$dir/$p", preg_replace($pattern, $embed, $html, 1));
            echo "  website: $p -> Murano form\n";
        }
    }

    public function down(): void
    {
        // Not undone: Website -> Front page -> Saved websites has the copy taken before.
    }
};
