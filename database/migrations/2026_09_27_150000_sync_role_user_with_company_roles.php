<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data fix: when roles were cloned per company (2026-09-22), users.role_id
 * was repointed at each user's own company's copy, but role_user -- the
 * table permissions are actually read from (User::roles()) -- still pointed
 * at company 1's original. Every non-company-1 user was effectively running
 * on company 1's role, and editing it had no visible effect.
 *
 * Resync role_user to users.role_id wherever that role belongs to the
 * user's own company. The copies carry identical permission sets, so no
 * one gains or loses access. SuperAdmin users are left alone (their Owner
 * role lives outside the per-company model). Idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $users = DB::table('users as u')
            ->join('roles as r', 'r.id', '=', 'u.role_id')
            ->whereColumn('r.company_id', 'u.company_id')
            ->where('u.type', '!=', 'SuperAdmin')
            ->get(['u.id', 'u.role_id']);

        foreach ($users as $u) {
            $rows = DB::table('role_user')->where('user_id', $u->id)->orderBy('id')->get(['id', 'role_id']);

            if ($rows->isEmpty()) {
                DB::table('role_user')->insert([
                    'user_id' => $u->id, 'role_id' => $u->role_id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                continue;
            }

            // Keep exactly one row per user, matching users.role_id.
            $keep = $rows->first();
            if ((int) $keep->role_id !== (int) $u->role_id) {
                DB::table('role_user')->where('id', $keep->id)->update(['role_id' => $u->role_id, 'updated_at' => now()]);
            }
            if ($rows->count() > 1) {
                DB::table('role_user')->where('user_id', $u->id)->where('id', '!=', $keep->id)->delete();
            }
        }
    }

    public function down(): void
    {
        // Not reversible: the old values were the inconsistent state.
    }
};
