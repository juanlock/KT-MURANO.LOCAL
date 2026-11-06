<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An admin / SuperAdmin login only manages the system's access (user's rule,
 * 2026-11-02): it is never an employee, customer, supplier or sales agent.
 * linked() lists the records a login belongs to; release() frees it before it
 * becomes an admin: its employee record is removed (soft delete + leaving date,
 * unlinked -- its history keeps pointing to it, and murano:link-missing-users
 * never gives a removed employee a new login), customer / supplier / sales agent
 * records are only unlinked. Also used by migration 2026_11_02_160000.
 */
class AdminLogins
{
    /** table => label of the record kinds a login can belong to. */
    public const RECORDS = ['employees' => 'employee', 'clients' => 'customer', 'providers' => 'supplier', 'sales_agents' => 'sales agent'];

    /** [['kind' => 'employee', 'id' => 12, 'name' => 'Ana Ruiz'], ...] */
    public static function linked(int $userId): array
    {
        $out = [];
        foreach (self::RECORDS as $table => $kind) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            foreach (DB::table($table)->where('user_id', $userId)->whereNull('deleted_at')->get() as $r) {
                $name = $table === 'employees' ? trim(($r->firstname ?? '').' '.($r->lastname ?? '')) : (string) ($r->name ?? '');
                $out[] = ['kind' => $kind, 'id' => (int) $r->id, 'name' => $name ?: "#{$r->id}"];
            }
        }

        return $out;
    }

    /** Frees the login from its records (call inside a transaction). Returns how many. */
    public static function release(int $userId): int
    {
        $now = now();
        $n = 0;
        foreach (self::RECORDS as $table => $kind) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
                continue;
            }
            $set = ['user_id' => null, 'updated_at' => $now];
            if ($table === 'employees') {
                $set['deleted_at'] = $now;
                if (Schema::hasColumn('employees', 'leaving_date')) {
                    $set['leaving_date'] = $now->toDateString();
                }
            }
            $n += DB::table($table)->where('user_id', $userId)->whereNull('deleted_at')->update($set);
        }

        return $n;
    }
}
