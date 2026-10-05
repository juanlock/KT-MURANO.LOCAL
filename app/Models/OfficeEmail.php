<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Email Office: one email, received (direction in) or sent (out) -- table
 * office_emails. Not to be confused with EmailMessage (email TEMPLATES).
 * See App\Services\EmailOffice\EmailOffice for how it's sorted and matched.
 */
class OfficeEmail extends Model
{
    protected $table = 'office_emails';

    // Set by hand from the Type menu (never guessed): supplier = from / about a
    // supplier but not a bill (order confirmations, quotes, deliveries);
    // work_order / request / question = what a customer is asking for.
    // Same list in resources/src/lib/emailCategories.js.
    // payment = a supplier's "we received your payment" (goes into its bill's history).
    // archive / spam work like folders: out of the normal list, shown only
    // when that type (card / filter) is picked.
    public const CATEGORIES = ['bill', 'claim', 'reminder', 'payment', 'supplier', 'work_order', 'request', 'question', 'general', 'social', 'archive', 'spam'];

    // social (e.g. TikTok / Facebook notifications, usually put there by a rule) is a folder too.
    public const HIDDEN_CATEGORIES = ['social', 'archive', 'spam'];

    /** The company's own folders as categories: "f{id}" (email_office_folders). null = every company's. */
    public static function customCategories(?int $companyId): array
    {
        static $cache = [];
        $k = (string) $companyId;
        if (! \Illuminate\Support\Facades\Schema::hasTable('email_office_folders')) {
            return [];
        }

        return $cache[$k] ??= \Illuminate\Support\Facades\DB::table('email_office_folders')
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->pluck('id')->map(fn ($id) => 'f'.$id)->all();
    }

    /** Every type an email of that company can have (built-in + its folders). */
    public static function categoriesFor(?int $companyId): array
    {
        return array_merge(self::CATEGORIES, self::customCategories($companyId));
    }

    /** Folders kept out of the normal list (built-in + the company's own). */
    public static function hiddenFor(?int $companyId): array
    {
        return array_merge(self::HIDDEN_CATEGORIES, self::customCategories($companyId));
    }

    protected $fillable = [
        'company_id', 'direction', 'source', 'message_id', 'message_hash', 'in_reply_to', 'subject', 'subject_norm', 'link_role',
        'from_email', 'from_name', 'to_emails', 'cc_emails', 'sent_at', 'body_text', 'body_html', 'snippet',
        'attachments', 'category', 'category_manual', 'party_type', 'party_id', 'linked_type', 'linked_id',
        'is_read', 'status', 'error',
    ];

    protected $casts = [
        'company_id' => 'integer',
        'attachments' => 'array',
        'category_manual' => 'boolean',
        'party_id' => 'integer',
        'linked_id' => 'integer',
        'is_read' => 'boolean',
        'sent_at' => 'datetime',
    ];
}
