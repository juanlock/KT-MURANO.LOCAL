<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * email_messages had a UNIQUE(name, locale) index from before company_id
 * existed on the table (2026_02_28_000000_add_locale_to_email_messages_table.php).
 * That index doesn't include company_id, so every company sharing the same
 * template name+locale collides on insert -- discovered while verifying the
 * mail/email-templates multicompany fix: no company could ever save its own
 * customized copy of a template, since one already exists in the pool
 * (company_id 999) for every name+locale combination. Replace it with a
 * UNIQUE(company_id, name, locale) index, which is what forCompanyLocale-style
 * lookups/saves actually need.
 */
return new class extends Migration
{
    public function up(): void
    {
        $indexExists = DB::select("SHOW INDEX FROM email_messages WHERE Key_name = 'email_messages_name_locale_unique'");
        if (! empty($indexExists)) {
            DB::statement('ALTER TABLE email_messages DROP INDEX email_messages_name_locale_unique');
        }

        $newIndexExists = DB::select("SHOW INDEX FROM email_messages WHERE Key_name = 'email_messages_company_name_locale_unique'");
        if (empty($newIndexExists)) {
            DB::statement('ALTER TABLE email_messages ADD UNIQUE email_messages_company_name_locale_unique (company_id, name(100), locale)');
        }
    }

    public function down(): void
    {
        $newIndexExists = DB::select("SHOW INDEX FROM email_messages WHERE Key_name = 'email_messages_company_name_locale_unique'");
        if (! empty($newIndexExists)) {
            DB::statement('ALTER TABLE email_messages DROP INDEX email_messages_company_name_locale_unique');
        }

        $oldIndexExists = DB::select("SHOW INDEX FROM email_messages WHERE Key_name = 'email_messages_name_locale_unique'");
        if (empty($oldIndexExists)) {
            DB::statement('ALTER TABLE email_messages ADD UNIQUE email_messages_name_locale_unique (name(100), locale)');
        }
    }
};
