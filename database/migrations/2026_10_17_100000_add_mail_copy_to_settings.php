<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail Settings -> "Send a copy to other emails" (2026-10-17): addresses that get
 * a hidden copy (BCC) of the emails sent to customers / suppliers, and which of
 * them -- sales (invoices, payment receipts), reminders, purchases.
 * App\Support\CompanyMail::copyEmails().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            if (! Schema::hasColumn('settings', 'mail_copy_emails')) {
                $t->text('mail_copy_emails')->nullable();
            }
            if (! Schema::hasColumn('settings', 'mail_copy_on')) {
                $t->string('mail_copy_on', 120)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $t) {
            foreach (['mail_copy_emails', 'mail_copy_on'] as $c) {
                if (Schema::hasColumn('settings', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
