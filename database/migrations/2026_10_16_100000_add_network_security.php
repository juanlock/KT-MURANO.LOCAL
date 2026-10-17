<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Network security (2026-10-16): staff can use the admin panel only from the
 * company's networks or from a computer an admin approved (App\Support\NetworkAccess).
 * Store, websites, portfolio, online booking and the portals are never affected;
 * the SuperAdmin is never blocked.
 *
 *  - settings.network_security_enabled   company switch (default off)
 *  - settings.network_allowed            JSON [{ip, label}] -- an IP or a range (CIDR)
 *  - settings.network_default_restricted users with no own choice follow this (default on)
 *  - users.network_access                null = company default | 'restricted' | 'anywhere'
 *  - trusted_devices                     approved computers (key kept in a browser cookie, hash here)
 *  - permission network_security         who manages it (Settings -> Network security)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            if (! Schema::hasColumn('settings', 'network_security_enabled')) $table->boolean('network_security_enabled')->default(false);
            if (! Schema::hasColumn('settings', 'network_allowed')) $table->text('network_allowed')->nullable();
            if (! Schema::hasColumn('settings', 'network_default_restricted')) $table->boolean('network_default_restricted')->default(true);
        });
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'network_access')) $table->string('network_access', 12)->nullable();
        });
        if (! Schema::hasTable('trusted_devices')) {
            Schema::create('trusted_devices', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('token_hash', 64)->index();
                $table->string('name', 190)->nullable();
                $table->string('status', 12)->default('pending'); // pending | approved | revoked
                $table->string('requested_ip', 64)->nullable();
                $table->string('last_ip', 64)->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->unsignedBigInteger('approved_by')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->timestamps();
            });
        }
        if (! DB::table('permissions')->where('name', 'network_security')->exists()) {
            $text = 'Network security: allowed networks, approved computers, who is restricted';
            $id = DB::table('permissions')->insertGetId(['name' => 'network_security', 'label' => $text, 'description' => $text, 'created_at' => now(), 'updated_at' => now()]);
            // Roles that manage the company's users get it (untick per role in Roles).
            $from = DB::table('permissions')->where('name', 'users_edit')->value('id');
            foreach ($from ? DB::table('permission_role')->where('permission_id', $from)->pluck('role_id')->unique() : [] as $rid) {
                DB::table('permission_role')->insert(['permission_id' => $id, 'role_id' => $rid]);
            }
        }
        // Menu name and the bell's "waiting" line.
        foreach (['Network_Security' => ['Network security', 'Seguridad de red'], 'Computers_To_Approve' => ['computers to approve', 'computadoras por aprobar']] as $key => [$en, $es]) {
            if (Schema::hasTable('translations') && ! DB::table('translations')->where('key', $key)->exists()) {
                DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('name', 'network_security')->value('id');
        if ($id) {
            DB::table('permission_role')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
        Schema::dropIfExists('trusted_devices');
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'network_access')) $table->dropColumn('network_access');
        });
        Schema::table('settings', function (Blueprint $table) {
            foreach (['network_security_enabled', 'network_allowed', 'network_default_restricted'] as $c) {
                if (Schema::hasColumn('settings', $c)) $table->dropColumn($c);
            }
        });
    }
};
