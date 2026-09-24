<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Login Page Settings' text fields become per-locale JSON maps
 * ({"en": "...", "es": "...", ...}) instead of one plain string -- the login
 * screen is guest-facing and already renders in whatever locale the visitor
 * picked (app()->getLocale(), via the page's own language switcher), but
 * until now the admin-authored copy itself was a single value shown
 * identically regardless of language. Widened to `text` (was `string`/
 * varchar(255)) because a 4-locale JSON blob of a full sentence easily
 * exceeds 255 characters. Existing single-language content is preserved by
 * wrapping it as {"en": "<existing value>"} -- nothing is lost, and it
 * degrades gracefully (falls back to en) for any locale not yet translated.
 */
return new class extends Migration
{
    private array $fields = [
        'login_hero_badge', 'login_hero_title', 'login_hero_subtitle',
        'login_hero_feature_1', 'login_hero_feature_2', 'login_hero_feature_3',
        'login_panel_title', 'login_panel_subtitle',
        'login_btn_text', 'login_footer_text',
    ];

    public function up(): void
    {
        // Snapshot existing plain-string values BEFORE widening the columns,
        // then re-wrap them as {"en": "..."} JSON after.
        $existing = DB::table('settings')
            ->select(array_merge(['id'], $this->fields))
            ->get();

        Schema::table('settings', function (Blueprint $table) {
            foreach ($this->fields as $field) {
                $table->text($field)->nullable()->change();
            }
        });

        foreach ($existing as $row) {
            $updates = [];
            foreach ($this->fields as $field) {
                $value = $row->$field;
                if ($value === null || $value === '') {
                    continue;
                }
                // Idempotent: if this ever re-runs and the value is already
                // valid per-locale JSON (starts with '{'), leave it alone.
                if (str_starts_with(trim($value), '{')) {
                    continue;
                }
                $updates[$field] = json_encode(['en' => $value], JSON_UNESCAPED_UNICODE);
            }
            if ($updates) {
                DB::table('settings')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        // Not reversible -- collapsing a per-locale map back to one plain
        // string would be lossy (which locale wins?) with no clear answer.
    }
};
