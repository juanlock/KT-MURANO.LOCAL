<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Client Portal: merge `portal_clients` into `users`.
 *
 * Every customer already has its own users row (type 'customer', linked by
 * clients.user_id, created by LinkedUserService::provision), so the portal
 * login becomes that user: the portal password/remember token move onto it
 * and a separate users.portal_enabled flag replaces portal_clients.status
 * (NOT users.statut -- that one gates every login, and user delete sets it).
 * preferred_locale / invitation_token / invitation_sent_at move over as-is.
 *
 * On an email mismatch the portal email wins (user decision 2026-09-27),
 * unless another live user already has it.
 *
 * portal_account_deletions (self-service closure audit) stays, gaining a
 * user_id alongside its client_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'portal_enabled')) {
                $table->tinyInteger('portal_enabled')->default(0);
            }
            if (! Schema::hasColumn('users', 'preferred_locale')) {
                $table->string('preferred_locale', 5)->nullable();
            }
            if (! Schema::hasColumn('users', 'invitation_token')) {
                $table->string('invitation_token', 64)->nullable()->index();
            }
            if (! Schema::hasColumn('users', 'invitation_sent_at')) {
                $table->timestamp('invitation_sent_at')->nullable();
            }
        });

        if (Schema::hasTable('portal_account_deletions') && ! Schema::hasColumn('portal_account_deletions', 'user_id')) {
            Schema::table('portal_account_deletions', function (Blueprint $table) {
                $table->unsignedInteger('user_id')->nullable()->index()->after('client_id');
            });
        }

        if (! Schema::hasTable('portal_clients')) {
            return;
        }

        foreach (DB::table('portal_clients')->get() as $pc) {
            $userId = DB::table('clients')->where('id', $pc->client_id)->value('user_id');
            if (! $userId) {
                // No linked user (shouldn't happen: every live customer has one).
                // Leave it for the admin to re-enable; nothing to attach it to.
                continue;
            }

            $update = [
                'portal_enabled' => (int) $pc->status === 1 ? 1 : 0,
                'preferred_locale' => $pc->preferred_locale,
                'invitation_token' => $pc->invitation_token,
                'invitation_sent_at' => $pc->invitation_sent_at,
            ];
            if ($pc->password) {
                $update['password'] = $pc->password;
            }
            if ($pc->remember_token) {
                $update['remember_token'] = $pc->remember_token;
            }
            $emailTaken = DB::table('users')->where('email', $pc->email)
                ->where('id', '!=', $userId)->whereNull('deleted_at')->exists();
            if ($pc->email && ! $emailTaken) {
                $update['email'] = $pc->email;
            }

            DB::table('users')->where('id', $userId)->update($update);
        }

        Schema::drop('portal_clients');
    }

    public function down(): void
    {
        // Recreate portal_clients from the customers with the portal enabled.
        if (! Schema::hasTable('portal_clients')) {
            Schema::create('portal_clients', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->integer('client_id');
                $table->string('email', 192)->unique();
                $table->string('password')->nullable();
                $table->tinyInteger('status')->default(0);
                $table->string('preferred_locale', 5)->nullable();
                $table->string('invitation_token', 64)->nullable();
                $table->timestamp('invitation_sent_at')->nullable();
                $table->rememberToken();
                $table->timestamps();
                $table->index(['email', 'status']);
                $table->foreign('client_id')->references('id')->on('clients')->onDelete('cascade')->onUpdate('cascade');
            });

            $rows = DB::table('users as u')->join('clients as c', 'c.user_id', '=', 'u.id')
                ->where('u.type', 'customer')->where('u.portal_enabled', 1)
                ->get(['c.id as client_id', 'u.email', 'u.password', 'u.preferred_locale', 'u.invitation_token', 'u.invitation_sent_at', 'u.remember_token']);
            foreach ($rows as $r) {
                DB::table('portal_clients')->insert((array) $r + ['status' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            foreach (['portal_enabled', 'preferred_locale', 'invitation_token', 'invitation_sent_at'] as $c) {
                if (Schema::hasColumn('users', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
