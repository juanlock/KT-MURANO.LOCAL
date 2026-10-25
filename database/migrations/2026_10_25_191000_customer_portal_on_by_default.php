<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Customer portal on by default, in every company (user's rule, 2026-10-25):
 *  - users.portal_enabled defaults to 1 (any path that creates a customer
 *    login; the portal guard only ever reads type customer rows);
 *  - every existing customer login gets it, except a deleted customer and
 *    one who closed their own portal account (portal_account_deletions).
 * An admin can still turn one off from People -> Customers. Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'portal_enabled')) {
            return;
        }
        DB::statement('ALTER TABLE users MODIFY portal_enabled TINYINT NOT NULL DEFAULT 1');

        $closed = Schema::hasTable('portal_account_deletions')
            ? DB::table('portal_account_deletions')->whereNotNull('user_id')->pluck('user_id')->all() : [];
        DB::table('users as u')
            ->where('u.type', 'customer')->whereNull('u.deleted_at')->where('u.portal_enabled', 0)
            ->whereNotIn('u.id', $closed ?: [0])
            ->whereNotExists(fn ($q) => $q->from('clients as c')->whereColumn('c.user_id', 'u.id')->whereNotNull('c.deleted_at'))
            ->update(['u.portal_enabled' => 1]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'portal_enabled')) {
            DB::statement('ALTER TABLE users MODIFY portal_enabled TINYINT NOT NULL DEFAULT 0');
        }
    }
};
