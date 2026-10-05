<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Email Office: each company's mailbox, imported into office_emails (not
 * email_messages: that table holds the email TEMPLATES) --
 * received (Inbox) and sent (the mailbox's Sent folder + every email the
 * system sends, logged by App\Listeners\LogSentEmail). Each email is sorted
 * (bill / claim / reminder / general), matched to its customer / supplier /
 * employee, and can be linked to a purchase, an expense or a helpdesk ticket.
 *
 * Permissions: email_office_view (read, link, compose), email_office_manage
 * (mailbox settings). Owners / admins get both. Sidebar: "Email Office"
 * after Helpdesk (Settings, Received, Sent).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_office_settings')) {
            Schema::create('email_office_settings', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->unique();
                $table->boolean('enabled')->default(false);
                $table->string('protocol', 8)->default('imap'); // imap | pop3
                $table->string('host')->nullable();
                $table->unsignedSmallInteger('port')->nullable();
                $table->string('encryption', 8)->default('ssl'); // ssl | tls | none
                $table->string('username')->nullable();
                $table->text('password')->nullable(); // encrypted cast
                $table->string('folder', 120)->default('INBOX');
                $table->boolean('import_sent')->default(true);
                $table->unsignedSmallInteger('check_every_minutes')->default(15);
                $table->unsignedSmallInteger('days_back')->default(30);
                $table->string('smtp_host')->nullable();
                $table->unsignedSmallInteger('smtp_port')->nullable();
                $table->string('smtp_encryption', 8)->nullable();
                $table->string('from_name')->nullable();
                $table->string('from_email')->nullable();
                $table->timestamp('last_polled_at')->nullable();
                $table->text('last_error')->nullable();
                $table->json('last_stats')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('office_emails')) {
            Schema::create('office_emails', function (Blueprint $table) {
                $table->id();
                $table->unsignedInteger('company_id')->nullable()->index();
                $table->string('direction', 4); // in | out
                $table->string('source', 12); // mailbox | system | compose
                $table->string('message_id', 512)->nullable();
                $table->string('message_hash', 64)->nullable(); // sha1 of message_id, for the unique index
                $table->string('in_reply_to', 512)->nullable();
                $table->string('subject', 998)->nullable();
                $table->string('from_email')->nullable()->index();
                $table->string('from_name')->nullable();
                $table->text('to_emails')->nullable(); // "a@x.com, b@y.com" (lower case)
                $table->text('cc_emails')->nullable();
                $table->dateTime('sent_at')->nullable()->index();
                $table->longText('body_text')->nullable();
                $table->longText('body_html')->nullable();
                $table->string('snippet', 300)->nullable();
                $table->json('attachments')->nullable(); // [{name, size, mime, path}]
                $table->string('category', 12)->default('general')->index(); // bill | claim | reminder | general
                $table->boolean('category_manual')->default(false);
                $table->string('party_type', 12)->nullable(); // client | provider | employee
                $table->unsignedInteger('party_id')->nullable();
                $table->string('linked_type', 20)->nullable(); // purchase | expense | helpdesk_ticket
                $table->unsignedBigInteger('linked_id')->nullable();
                $table->boolean('is_read')->default(false);
                $table->string('status', 12)->default('ok'); // ok | failed (system sends)
                $table->text('error')->nullable();
                $table->timestamps();
                $table->unique(['company_id', 'message_hash']);
                $table->index(['party_type', 'party_id']);
                $table->index(['linked_type', 'linked_id']);
            });
        }

        $this->permissions();
        $this->menu();
        $this->translations();
    }

    private function permissions(): void
    {
        $now = now();
        $perm = [];
        foreach (['email_office_view' => 'Email Office: read, link and send emails', 'email_office_manage' => 'Email Office: mailbox settings'] as $name => $label) {
            $id = DB::table('permissions')->where('name', $name)->value('id')
                ?: DB::table('permissions')->insertGetId(['name' => $name, 'label' => $label, 'description' => $label, 'created_at' => $now, 'updated_at' => $now]);
            $perm[] = (int) $id;
        }
        foreach (DB::table('roles')->whereNull('deleted_at')->whereIn('name', ['Owner', 'Admin', 'Admin-ALL'])->pluck('id') as $rid) {
            foreach ($perm as $pid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }
    }

    /** Sidebar: right after Helpdesk (same parent), for companies with a saved layout. */
    private function menu(): void
    {
        $now = now();
        $children = ['/app/email-office/settings', '/app/email-office/received', '/app/email-office/sent'];
        foreach (DB::table('menu_positions')->where('item_key', 'helpdesk')->get(['company_id', 'parent_key', 'sort_order', 'location']) as $hd) {
            $cid = $hd->company_id;
            if (DB::table('menu_positions')->where('company_id', $cid)->where('item_key', 'email_office')->exists()) {
                continue;
            }
            DB::table('menu_positions')->where('company_id', $cid)
                ->where(fn ($q) => $hd->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $hd->parent_key))
                ->where('sort_order', '>', $hd->sort_order)->increment('sort_order');
            $rows = [[
                'company_id' => $cid, 'item_key' => 'email_office', 'parent_key' => $hd->parent_key, 'sort_order' => $hd->sort_order + 1,
                'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => $hd->location ?: 'sidebar', 'created_at' => $now, 'updated_at' => $now,
            ]];
            foreach ($children as $i => $key) {
                $rows[] = [
                    'company_id' => $cid, 'item_key' => $key, 'parent_key' => 'email_office', 'sort_order' => $i,
                    'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            DB::table('menu_positions')->insert($rows);
        }
    }

    private function translations(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }
        $keys = [
            'Email_Office' => ['Email Office', 'Oficina de correo', 'Bureau e-mail'],
            'Email_Office_Settings' => ['Settings', 'Configuración', 'Paramètres'],
            'Email_Received' => ['Email received', 'Correos recibidos', 'E-mails reçus'],
            'Email_Sent' => ['Email sent', 'Correos enviados', 'E-mails envoyés'],
            'Email_Conversation' => ['Email conversation', 'Conversación por correo', 'Conversation e-mail'],
        ];
        $hasFr = Schema::hasColumn('translations', 'fr');
        foreach ($keys as $key => [$en, $es, $fr]) {
            if (DB::table('translations')->where('key', $key)->exists()) {
                continue;
            }
            DB::table('translations')->insert(array_filter([
                'key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'fr' => $hasFr ? $fr : null,
                'created_at' => now(), 'updated_at' => now(),
            ], fn ($v) => $v !== null));
        }
    }

    public function down(): void
    {
        DB::table('menu_positions')->where('item_key', 'email_office')->orWhere('parent_key', 'email_office')->delete();
        $ids = DB::table('permissions')->whereIn('name', ['email_office_view', 'email_office_manage'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('translations')->whereIn('key', ['Email_Office', 'Email_Office_Settings', 'Email_Received', 'Email_Sent', 'Email_Conversation'])->delete();
        Schema::dropIfExists('office_emails');
        Schema::dropIfExists('email_office_settings');
    }
};
