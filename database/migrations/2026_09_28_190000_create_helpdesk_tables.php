<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk (per company): tickets opened by employees (app), customers
 * (client portal), online customers (store account), suppliers / anyone
 * (public form or email), managed by the company's agents.
 *
 *  helpdesk_settings  one row per company: numbering, public form, IMAP
 *                     mailbox (read -> tickets/replies) + optional SMTP
 *  helpdesk_tickets   the tickets
 *  helpdesk_messages  the conversation (requester, agents, internal notes)
 *
 * Permissions: helpdesk_view (work tickets), helpdesk_manage (settings,
 * mailbox, delete). New per-company role "Helpdesk" (agents) with
 * helpdesk_view (+ intranet_view when that module is installed), also on the
 * template company 999 so CompanyProvisioner copies it to new companies.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('helpdesk_settings')) {
            Schema::create('helpdesk_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->unique();
                $t->string('ticket_prefix', 12)->default('HD-');
                $t->boolean('public_form_enabled')->default(true);
                $t->boolean('auto_reply')->default(true);
                $t->text('categories')->nullable(); // JSON list of names
                $t->boolean('imap_enabled')->default(false);
                $t->string('imap_host', 190)->nullable();
                $t->unsignedSmallInteger('imap_port')->nullable();
                $t->string('imap_encryption', 10)->nullable(); // ssl | tls | none
                $t->string('imap_username', 190)->nullable();
                $t->text('imap_password')->nullable(); // encrypted
                $t->string('imap_folder', 120)->default('INBOX');
                $t->string('smtp_host', 190)->nullable();
                $t->unsignedSmallInteger('smtp_port')->nullable();
                $t->string('smtp_encryption', 10)->nullable();
                $t->string('from_email', 190)->nullable(); // defaults to the mailbox username
                $t->string('from_name', 190)->nullable();
                $t->timestamp('last_polled_at')->nullable();
                $t->text('last_error')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('helpdesk_tickets')) {
            Schema::create('helpdesk_tickets', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('number', 30);
                $t->string('subject', 250);
                $t->string('status', 20)->default('open');     // open | pending | resolved | closed
                $t->string('priority', 20)->default('normal'); // low | normal | high | urgent
                $t->string('category', 80)->nullable();
                $t->string('source', 20)->default('app');      // app | portal | store | public_form | email | admin
                $t->string('requester_type', 20)->default('guest'); // employee | customer | online_customer | supplier | guest
                $t->unsignedInteger('requester_user_id')->nullable();
                $t->unsignedInteger('client_id')->nullable();
                $t->unsignedInteger('provider_id')->nullable();
                $t->string('requester_name', 190)->nullable();
                $t->string('requester_email', 190)->nullable()->index();
                $t->unsignedInteger('assigned_to')->nullable();
                $t->string('public_token', 64)->unique();
                $t->timestamp('last_message_at')->nullable();
                $t->timestamp('first_response_at')->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'number']);
                $t->index(['company_id', 'status']);
            });
        }

        if (! Schema::hasTable('helpdesk_messages')) {
            Schema::create('helpdesk_messages', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('ticket_id')->index();
                $t->string('author_type', 20); // requester | agent | system
                $t->unsignedInteger('user_id')->nullable();
                $t->string('author_name', 190)->nullable();
                $t->string('author_email', 190)->nullable();
                $t->longText('body');
                $t->boolean('is_internal')->default(false);
                $t->string('via', 20)->default('app'); // app | portal | store | public_form | email
                $t->string('email_message_id', 250)->nullable()->index();
                $t->text('attachments')->nullable(); // JSON [{name, path, size, mime}]
                $t->timestamps();
            });
        }

        $this->permissionsAndRole();
        $this->translations();
    }

    private function permissionsAndRole(): void
    {
        $now = now();
        $perm = [];
        foreach (['helpdesk_view' => 'Helpdesk: work tickets', 'helpdesk_manage' => 'Helpdesk: settings & mailbox'] as $name => $label) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $id = DB::table('permissions')->insertGetId(['name' => $name, 'label' => $label, 'description' => $label, 'created_at' => $now, 'updated_at' => $now]);
            }
            $perm[$name] = $id;
        }
        $grant = function (int $roleId, int $permId) {
            if (! DB::table('permission_role')->where('role_id', $roleId)->where('permission_id', $permId)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permId]);
            }
        };

        // Owners/admins manage the helpdesk.
        foreach (DB::table('roles')->whereNull('deleted_at')->whereIn('name', ['Owner', 'Admin', 'Admin-ALL'])->pluck('id') as $rid) {
            $grant((int) $rid, $perm['helpdesk_view']);
            $grant((int) $rid, $perm['helpdesk_manage']);
        }

        // A "Helpdesk" (agent) role in every company that has roles, template 999 included.
        $roleCols = Schema::getColumnListing('roles');
        $intranetView = DB::table('permissions')->where('name', 'intranet_view')->value('id');
        foreach (DB::table('roles')->whereNull('deleted_at')->whereNotNull('company_id')->distinct()->pluck('company_id') as $companyId) {
            $rid = DB::table('roles')->where('company_id', $companyId)->where('name', 'Helpdesk')->value('id');
            if (! $rid) {
                $row = ['name' => 'Helpdesk', 'company_id' => $companyId, 'created_at' => $now, 'updated_at' => $now];
                if (in_array('label', $roleCols, true)) {
                    $row['label'] = 'Helpdesk';
                }
                if (in_array('description', $roleCols, true)) {
                    $row['description'] = 'Helpdesk agent: sees and answers tickets';
                }
                if (in_array('status', $roleCols, true)) {
                    $row['status'] = DB::table('roles')->where('company_id', $companyId)->where('name', 'Employee')->value('status') ?? 1;
                }
                if (in_array('guard_name', $roleCols, true)) {
                    $row['guard_name'] = 'web';
                }
                $rid = DB::table('roles')->insertGetId($row);
            }
            $grant((int) $rid, $perm['helpdesk_view']);
            if ($intranetView) {
                $grant((int) $rid, (int) $intranetView);
            }
        }
    }

    private function translations(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        $keys = [
            'Helpdesk' => ['Helpdesk', 'Mesa de ayuda', 'Assistance'],
            'Helpdesk_Dashboard' => ['Dashboard', 'Panel', 'Tableau de bord'],
            'Helpdesk_Tickets' => ['Tickets', 'Tickets', 'Tickets'],
            'Helpdesk_My_Tickets' => ['My tickets', 'Mis tickets', 'Mes tickets'],
            'Helpdesk_Settings' => ['Helpdesk settings', 'Configuración de la mesa de ayuda', 'Paramètres de l\'assistance'],
            'Helpdesk_New_Ticket' => ['New ticket', 'Nuevo ticket', 'Nouveau ticket'],
        ];
        $locales = array_diff(Schema::getColumnListing('translations'), ['id', 'key', 'is_default', 'customized_locales', 'created_at', 'updated_at']);
        foreach ($keys as $key => [$en, $es, $fr]) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            $row = ['key' => $key, 'is_default' => 1, 'created_at' => now(), 'updated_at' => now()];
            foreach ($locales as $l) {
                $row[$l] = $l === 'es' ? $es : ($l === 'fr' ? $fr : $en);
            }
            DB::table('translations')->insert($row);
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_messages');
        Schema::dropIfExists('helpdesk_tickets');
        Schema::dropIfExists('helpdesk_settings');
        $ids = DB::table('permissions')->whereIn('name', ['helpdesk_view', 'helpdesk_manage'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        $roles = DB::table('roles')->where('name', 'Helpdesk')->pluck('id');
        DB::table('permission_role')->whereIn('role_id', $roles)->delete();
        DB::table('roles')->whereIn('id', $roles)->delete();
    }
};
