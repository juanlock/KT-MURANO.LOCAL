<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Server extends Model
{
    protected $fillable = [
        'company_id', 'mail_mailer', 'sender_name', 'sender_email', 'host', 'port', 'username', 'password', 'encryption',
    ];

    protected $casts = [
        'port' => 'integer',
        'company_id' => 'integer',
    ];

    /** AdminSystem's mail server is the template every company copies (user's rule, 2026-10-20). */
    public const TEMPLATE_COMPANY_ID = 999;

    /** Fields copied from the template; sender_name is always "Admin {company}". */
    private const TEMPLATE_FIELDS = ['mail_mailer', 'host', 'port', 'username', 'password', 'encryption', 'sender_email'];

    /**
     * Each company's own SMTP config. A company without one gets a copy of
     * AdminSystem's (999) mail server with the sender name "Admin {company}"
     * (user's rule, 2026-10-20: same server for every company); blank only
     * when the template has no server yet.
     */
    public static function forCompany(?int $companyId): self
    {
        $companyId = $companyId ?: 1;
        $existing = static::where('company_id', $companyId)->whereNull('deleted_at')->first();
        if ($existing) {
            return $existing;
        }

        $row = static::templateValues($companyId) ?? ['mail_mailer' => 'smtp', 'encryption' => 'tls'];

        return static::create(['company_id' => $companyId] + $row);
    }

    /** The template's values for a company (sender name "Admin {company}"), or null when the template has no host. */
    public static function templateValues(int $companyId): ?array
    {
        $tpl = static::where('company_id', self::TEMPLATE_COMPANY_ID)->whereNull('deleted_at')->first();
        if (! $tpl || ! $tpl->host) {
            return null;
        }
        $values = [];
        foreach (self::TEMPLATE_FIELDS as $f) {
            $values[$f] = $tpl->getRawOriginal($f);
        }
        $name = \Illuminate\Support\Facades\DB::table('companies')->where('id', $companyId)->value('name');
        $values['sender_name'] = trim('Admin '.($name ?: ''));

        return $values;
    }

    /**
     * Copy AdminSystem's mail server to every company (one transaction):
     * each keeps its own row, only the values change; sender name =
     * "Admin {company}". Returns how many companies were updated.
     */
    public static function deployTemplate(): int
    {
        return \Illuminate\Support\Facades\DB::transaction(function () {
            $count = 0;
            $ids = \Illuminate\Support\Facades\DB::table('companies')->whereNull('deleted_at')
                ->where('id', '!=', self::TEMPLATE_COMPANY_ID)->pluck('id');
            foreach ($ids as $cid) {
                $values = static::templateValues((int) $cid);
                if ($values === null) {
                    return 0;
                }
                $row = static::where('company_id', $cid)->whereNull('deleted_at')->first();
                $row ? $row->update($values) : static::create(['company_id' => (int) $cid] + $values);
                $count++;
            }

            return $count;
        });
    }
}
