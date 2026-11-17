<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Call center module (user's request 2026-11-17, Alo Mundo): campaigns built from a filter of the leads (age, sex, location...),
 * agents who take a campaign and call the next lead nobody else has (cc_campaign_leads claimed one at a time), the call log,
 * leads with import, agents' shifts and sessions (pauses), floor indicators, quality reviews, billing per campaign to the
 * client (a sale), and the telephone exchange (webhook + click to call) for the call centers that dial through one.
 * Business module `callcenter`, off by default (on for the call center type). Safe to run twice.
 */
return new class extends Migration
{
    private const PERMS = [
        'callcenter_work' => ['Call center: my campaign (call the leads)', 'Centro de llamadas: mi campaña (llamar a los leads)'],
        'callcenter_campaigns' => ['Call center: campaigns', 'Centro de llamadas: campañas'],
        'callcenter_leads' => ['Call center: leads and import', 'Centro de llamadas: leads e importación'],
        'callcenter_calls' => ['Call center: every call (call log)', 'Centro de llamadas: todas las llamadas (registro)'],
        'callcenter_shifts' => ['Call center: agents\' shifts', 'Centro de llamadas: turnos de agentes'],
        'callcenter_dashboard' => ['Call center: floor indicators', 'Centro de llamadas: indicadores del piso'],
        'callcenter_quality' => ['Call center: quality reviews', 'Centro de llamadas: calidad'],
        'callcenter_billing' => ['Call center: billing to clients', 'Centro de llamadas: facturación a clientes'],
        'callcenter_telephony' => ['Call center: telephone exchange', 'Centro de llamadas: central telefónica'],
    ];

    private const TEXTS = [
        'CallCenter' => ['Call center', 'Centro de llamadas'],
        'CC_Work' => ['My campaign', 'Mi campaña'],
        'CC_Campaigns' => ['Campaigns', 'Campañas'],
        'CC_Leads' => ['Leads', 'Leads'],
        'CC_Calls' => ['Call log', 'Registro de llamadas'],
        'CC_Shifts' => ['Agents\' shifts', 'Turnos de agentes'],
        'CC_Dashboard' => ['Floor indicators', 'Indicadores del piso'],
        'CC_Quality' => ['Quality', 'Calidad'],
        'CC_Billing' => ['Client billing', 'Facturación a clientes'],
        'CC_Telephony' => ['Telephone exchange', 'Central telefónica'],
        'Perm_CC_Work' => ['My campaign: take a campaign and call its leads', 'Mi campaña: tomar una campaña y llamar a sus leads'],
        'Perm_CC_Campaigns' => ['Campaigns: create, filter the leads, start / pause', 'Campañas: crear, filtrar los leads, iniciar / pausar'],
        'Perm_CC_Leads' => ['Leads: add, edit, import', 'Leads: agregar, editar, importar'],
        'Perm_CC_Calls' => ['Call log: every agent\'s calls', 'Registro de llamadas: las de todos los agentes'],
        'Perm_CC_Shifts' => ['Agents\' shifts and attendance', 'Turnos y asistencia de agentes'],
        'Perm_CC_Dashboard' => ['Floor indicators', 'Indicadores del piso'],
        'Perm_CC_Quality' => ['Quality: review calls', 'Calidad: evaluar llamadas'],
        'Perm_CC_Billing' => ['Billing to the clients per campaign', 'Facturación a clientes por campaña'],
        'Perm_CC_Telephony' => ['Telephone exchange settings', 'Configuración de la central telefónica'],
    ];

    /** Alo Mundo keeps only what a call center uses (user, 2026-11-17: "los módulos que no son relativos a callcenter apágalos"). */
    private const ALO_ON = ['callcenter', 'customers', 'sales', 'hrm', 'recruit', 'helpdesk', 'forms'];

    private const ALL_MODULES = ['accounting', 'assets', 'bookings', 'commissions', 'customers', 'documents', 'email_office', 'EWallet', 'fleet', 'forms',
        'gallery', 'google_sheets', 'helpdesk', 'hospital', 'hrm', 'integrations', 'intranet', 'jumia', 'kitchen', 'mailchimp', 'manufacturing', 'marketing',
        'meeting', 'paypal', 'portfolio', 'pos', 'prestashop', 'products', 'projects', 'promotions', 'purchases', 'quickbooks', 'realestate', 'recruit', 'sales',
        'salla', 'school', 'service', 'shopify', 'slack', 'Store', 'subscription_product', 'suppliers', 'telegram', 'transport', 'website',
        'woocommerce_settings', 'xero', 'zatca', 'callcenter'];

    public function up(): void
    {
        $this->tables();

        // Permissions: IT Admin every one; Manager every one but the exchange's settings (configuration).
        foreach (self::PERMS as $name => [$en]) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $en, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id', 'name');
        foreach (DB::table('roles')->whereIn('name', ['IT Admin', 'Manager'])->whereNull('deleted_at')->get(['id', 'name']) as $role) {
            foreach ($ids as $name => $pid) {
                if ($role->name === 'Manager' && $name === 'callcenter_telephony') {
                    continue;
                }
                if (! DB::table('permission_role')->where('role_id', $role->id)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $role->id, 'permission_id' => $pid]);
                }
            }
        }

        foreach (self::PERMS + self::TEXTS as $key => [$en, $es]) {
            TranslationKeys::ensure($key, ['es' => $es]);
            foreach (['en' => $en, 'es' => $es] as $col => $text) {
                DB::table('translations')->whereRaw('`key` = ? COLLATE utf8mb4_bin', [$key])
                    ->where(fn ($q) => $q->whereNull($col)->orWhere($col, '')->orWhere($col, $key))
                    ->update([$col => $text, 'updated_at' => now()]);
            }
        }
        if (function_exists('forget_db_translations')) {
            forget_db_translations();
        }

        // Every call center company: the module on; Alo Mundo: only the call center's modules.
        foreach (DB::table('companies')->where('business_type', 'call_center')->whereNull('deleted_at')->pluck('id') as $cid) {
            $raw = DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->value('module_flags');
            $flags = (is_string($raw) ? json_decode($raw, true) : $raw) ?: [];
            $flags['callcenter'] = true;
            DB::table('settings')->where('company_id', $cid)->whereNull('deleted_at')->update(['module_flags' => json_encode($flags)]);
        }
        $alo = DB::table('companies')->where('name', 'Alo Mundo')->whereNull('deleted_at')->value('id');
        if ($alo) {
            $flags = [];
            foreach (self::ALL_MODULES as $m) {
                $flags[$m] = in_array($m, self::ALO_ON, true);
            }
            DB::table('settings')->where('company_id', $alo)->whereNull('deleted_at')->update(['module_flags' => json_encode($flags)]);
            // The group right after the dashboard in its menu.
            if (! DB::table('menu_positions')->where('company_id', $alo)->where('item_key', 'callcenter')->exists()) {
                $dash = DB::table('menu_positions')->where('company_id', $alo)->whereNull('parent_key')->where('item_key', 'dashboard')->value('sort_order');
                $at = $dash !== null ? (int) $dash + 1 : 0;
                DB::table('menu_positions')->where('company_id', $alo)->whereNull('parent_key')->where('sort_order', '>=', $at)->increment('sort_order');
                DB::table('menu_positions')->insert(['company_id' => $alo, 'item_key' => 'callcenter', 'parent_key' => null, 'sort_order' => $at,
                    'external' => 0, 'is_custom' => 0, 'hidden' => 0, 'location' => 'sidebar', 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    private function tables(): void
    {
        if (! Schema::hasTable('cc_leads')) {
            Schema::create('cc_leads', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('first_name', 120)->nullable();
                $t->string('last_name', 120)->nullable();
                $t->string('phone', 40);
                $t->string('phone_key', 20)->index();       // digits only, last 9: the duplicate check
                $t->string('phone2', 40)->nullable();
                $t->string('email', 190)->nullable();
                $t->string('document', 30)->nullable();     // DNI / ID
                $t->date('birth_date')->nullable();
                $t->unsignedTinyInteger('age')->nullable(); // when only the age is known (as of created_at)
                $t->char('gender', 1)->nullable();           // M / F
                $t->string('region', 100)->nullable();       // departamento / state
                $t->string('province', 100)->nullable();
                $t->string('district', 100)->nullable();
                $t->string('city', 100)->nullable();
                $t->string('address', 255)->nullable();
                $t->string('source', 120)->nullable();
                $t->json('extra')->nullable();              // the file's other columns {name: value}
                $t->boolean('do_not_call')->default(false);
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('import_id')->nullable()->index();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['company_id', 'phone_key']);
            });
        }
        if (! Schema::hasTable('cc_imports')) {
            Schema::create('cc_imports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('file_name', 190);
                $t->string('file_path', 255)->nullable();
                $t->json('mapping')->nullable();
                $t->unsignedInteger('rows')->default(0);
                $t->unsignedInteger('created')->default(0);
                $t->unsignedInteger('updated')->default(0);
                $t->unsignedInteger('skipped')->default(0);
                $t->json('errors')->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cc_campaigns')) {
            Schema::create('cc_campaigns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('name', 150);
                $t->unsignedBigInteger('client_id')->nullable();  // the company the calls are made for
                $t->string('mode', 12)->default('screen');         // screen = from the leads on screen; telephony = through the exchange
                $t->string('status', 12)->default('draft');        // draft / active / paused / finished
                $t->text('script')->nullable();
                $t->json('filters')->nullable();
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->time('call_from')->nullable();
                $t->time('call_to')->nullable();
                $t->unsignedTinyInteger('max_attempts')->default(3);
                $t->unsignedSmallInteger('retry_minutes')->default(120);
                $t->string('billing_mode', 8)->default('none');    // none / hour / call / sale
                $t->decimal('billing_rate', 12, 2)->default(0);
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('cc_campaign_leads')) {
            Schema::create('cc_campaign_leads', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('campaign_id');
                $t->unsignedBigInteger('lead_id')->index();
                $t->string('status', 10)->default('pending');       // pending / locked / callback / done / exhausted
                $t->unsignedTinyInteger('attempts')->default(0);
                $t->unsignedBigInteger('locked_by')->nullable();
                $t->dateTime('locked_at')->nullable();
                $t->string('claim', 40)->nullable();
                $t->dateTime('next_call_at')->nullable();
                $t->unsignedBigInteger('callback_user_id')->nullable();
                $t->string('last_result', 20)->nullable();
                $t->unsignedBigInteger('last_user_id')->nullable();
                $t->unsignedBigInteger('last_call_id')->nullable();
                $t->timestamps();
                $t->unique(['campaign_id', 'lead_id']);
                $t->index(['campaign_id', 'status', 'next_call_at']);
            });
        }
        if (! Schema::hasTable('cc_calls')) {
            Schema::create('cc_calls', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('campaign_id')->nullable()->index();
                $t->unsignedBigInteger('lead_id')->nullable()->index();
                $t->unsignedBigInteger('campaign_lead_id')->nullable();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->string('direction', 8)->default('outbound');
                $t->string('source', 10)->default('screen');         // screen / telephony
                $t->string('phone', 40)->nullable();
                $t->dateTime('started_at')->index();
                $t->dateTime('ended_at')->nullable();
                $t->unsignedInteger('duration_sec')->default(0);
                $t->string('result', 20)->nullable();
                $t->dateTime('callback_at')->nullable();
                $t->decimal('sale_amount', 12, 2)->nullable();
                $t->text('notes')->nullable();
                $t->string('external_id', 120)->nullable()->index();
                $t->string('recording_url', 500)->nullable();
                $t->string('extension', 20)->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cc_agent_sessions')) {
            Schema::create('cc_agent_sessions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('campaign_id')->nullable();
                $t->string('kind', 6)->default('work');               // work / pause
                $t->string('reason', 20)->nullable();                // pause: break / lunch / training / restroom / other
                $t->dateTime('started_at');
                $t->dateTime('ended_at')->nullable();
                $t->dateTime('last_seen_at')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'ended_at']);
            });
        }
        if (! Schema::hasTable('cc_shifts')) {
            Schema::create('cc_shifts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->date('day');
                $t->time('start_time');
                $t->time('end_time');
                $t->unsignedBigInteger('campaign_id')->nullable();
                $t->string('notes', 255)->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'day']);
            });
        }
        if (! Schema::hasTable('cc_qa_criteria')) {
            Schema::create('cc_qa_criteria', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->string('name', 150);
                $t->unsignedTinyInteger('weight')->default(1);
                $t->unsignedSmallInteger('sort')->default(0);
                $t->boolean('active')->default(true);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cc_qa_reviews')) {
            Schema::create('cc_qa_reviews', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('call_id')->unique();
                $t->unsignedBigInteger('agent_id')->nullable()->index();
                $t->unsignedBigInteger('reviewer_id')->nullable();
                $t->json('scores');                                  // {criterion_id: 0..5}
                $t->decimal('score', 5, 1);                          // weighted %
                $t->text('comments')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cc_billings')) {
            Schema::create('cc_billings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->index();
                $t->unsignedBigInteger('campaign_id');
                $t->unsignedBigInteger('client_id')->nullable();
                $t->date('period_from');
                $t->date('period_to');
                $t->string('mode', 8);
                $t->decimal('quantity', 12, 2);
                $t->decimal('rate', 12, 2);
                $t->decimal('amount', 12, 2);
                $t->unsignedBigInteger('sale_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('cc_settings')) {
            Schema::create('cc_settings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('company_id')->unique();
                $t->string('provider', 20)->default('none');         // none / asterisk / 3cx / twilio / other
                $t->string('webhook_token', 64)->nullable()->unique();
                $t->string('dial_url', 500)->nullable();             // click to call: {extension} {phone}
                $t->json('extensions')->nullable();                  // {user_id: extension}
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        foreach (['cc_settings', 'cc_billings', 'cc_qa_reviews', 'cc_qa_criteria', 'cc_shifts', 'cc_agent_sessions', 'cc_calls', 'cc_campaign_leads', 'cc_campaigns', 'cc_imports', 'cc_leads'] as $t) {
            Schema::dropIfExists($t);
        }
        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMS))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
        DB::table('menu_positions')->where('item_key', 'callcenter')->delete();
    }
};
