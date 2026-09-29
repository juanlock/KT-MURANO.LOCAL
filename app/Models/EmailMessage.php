<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailMessage extends Model
{
    protected $table = 'email_messages';

    protected $fillable = [
        'company_id', 'name', 'locale', 'subject', 'body',
    ];

    protected $casts = [
        'company_id' => 'integer',
    ];

    /**
     * Get template by name/locale for a specific company, falling back to
     * the shared template pool (company_id = Setting::TEMPLATE_COMPANY_ID)
     * when the company hasn't customized this template, then to 'en' at
     * each level -- mirrors Setting::forCompany()'s clone/fallback
     * convention, appropriate here since template content (unlike an SMTP
     * password) is safe to share as a starting point. $companyId null keeps
     * the old pre-multicompany behavior (pool only) for any caller that
     * genuinely has no company context.
     */
    public static function getForLocale(string $name, ?string $locale = null, ?int $companyId = null): ?self
    {
        $locale = $locale ?: (Setting::forCompany($companyId)->default_language ?? 'en');

        if ($companyId) {
            $message = static::where('company_id', $companyId)
                ->where('name', $name)
                ->where('locale', $locale)
                ->whereNull('deleted_at')
                ->first();
            if ($message) {
                return $message;
            }
            if ($locale !== 'en') {
                $message = static::where('company_id', $companyId)
                    ->where('name', $name)
                    ->where('locale', 'en')
                    ->whereNull('deleted_at')
                    ->first();
                if ($message) {
                    return $message;
                }
            }
        }

        $poolId = Setting::TEMPLATE_COMPANY_ID;
        $message = static::where('company_id', $poolId)
            ->where('name', $name)
            ->where('locale', $locale)
            ->whereNull('deleted_at')
            ->first();
        if ($message) {
            return $message;
        }
        if ($locale !== 'en') {
            return static::where('company_id', $poolId)
                ->where('name', $name)
                ->where('locale', 'en')
                ->whereNull('deleted_at')
                ->first();
        }

        return null;
    }
}
