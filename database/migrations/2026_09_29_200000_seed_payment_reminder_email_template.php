<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the default 'payment_reminder' template into the shared pool
 * (company_id = Setting::TEMPLATE_COMPANY_ID = 999) so a fresh install (or a
 * company that hasn't customized it) has real starter content instead of a
 * blank Email Templates entry -- same convention as the original
 * 2026_02_28_000001_seed_email_templates_for_all_languages.php seed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('email_messages')
            ->where('company_id', 999)
            ->where('name', 'payment_reminder')
            ->where('locale', 'en')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('email_messages')->insert([
            'company_id' => 999,
            'name' => 'payment_reminder',
            'locale' => 'en',
            'subject' => 'Payment reminder: Invoice {invoice_number} — {business_name}',
            'body' => '<p>Hi {contact_name},</p>'
                .'<p>This is a friendly reminder that invoice <strong>{invoice_number}</strong> from {business_name} still has an outstanding balance.</p>'
                .'<p>Total amount: {total_amount}<br>Paid so far: {paid_amount}<br><strong>Balance due: {due_amount}</strong></p>'
                .'<p>You can view the invoice here: <a href="{invoice_url}">{invoice_url}</a></p>'
                .'<p>Please arrange payment at your earliest convenience. If you have already paid, kindly disregard this reminder.</p>'
                .'<p>Thank you,<br>{business_name}</p>',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('email_messages')
            ->where('company_id', 999)
            ->where('name', 'payment_reminder')
            ->where('locale', 'en')
            ->delete();
    }
};
