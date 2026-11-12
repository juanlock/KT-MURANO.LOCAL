<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change history (user's request, 2026-11-12): every create / change / delete made through the app's models, with
 * who, when, from where and the values before and after (App\Support\AuditTrail). Seen only by the SuperAdmin
 * ("Goku"): Settings -> Historial de cambios (superAdminOnly; AdminSystem = every company, a company picked = that
 * one). Its permission, the menu entry after KT-Log in every company's menu and the texts. Safe to run twice.
 */
return new class extends Migration
{
    private const KEY = '/app/settings/audit_log';

    private const AFTER = '/app/settings/kt_log';

    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            Schema::create('audit_logs', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->nullable()->index();
                $t->unsignedInteger('user_id')->nullable()->index();
                $t->string('user_name', 150)->nullable();
                $t->unsignedInteger('as_admin_id')->nullable();    // a company admin working as this login (Company logins -> Enter)
                $t->string('event', 12);                           // created | updated | deleted | restored
                $t->string('table_name', 64);
                $t->string('model', 120);
                $t->string('record_id', 40)->nullable();
                $t->json('changes')->nullable();                   // {field: [before, after]}
                $t->string('ip', 45)->nullable();
                $t->string('method', 8)->nullable();
                $t->string('url', 255)->nullable();
                $t->timestamp('created_at')->nullable()->index();
                $t->index(['table_name', 'record_id']);
                $t->index(['company_id', 'created_at']);
            });
        }

        if (Schema::hasTable('permissions') && ! DB::table('permissions')->where('name', 'audit_log_view')->exists()) {
            DB::table('permissions')->insert(['name' => 'audit_log_view', 'label' => 'Change history', 'created_at' => now(), 'updated_at' => now()]);
        }

        if (Schema::hasTable('menu_positions')) {
            foreach (DB::table('menu_positions')->where('item_key', self::AFTER)->get(['company_id', 'parent_key', 'sort_order', 'location', 'hidden']) as $ref) {
                if (DB::table('menu_positions')->where('company_id', $ref->company_id)->where('item_key', self::KEY)->exists()) {
                    continue;
                }
                $same = fn ($q) => $ref->parent_key === null ? $q->whereNull('parent_key') : $q->where('parent_key', $ref->parent_key);
                DB::table('menu_positions')->where('company_id', $ref->company_id)->where($same)->where('sort_order', '>', $ref->sort_order)->increment('sort_order');
                DB::table('menu_positions')->insert([
                    'company_id' => $ref->company_id, 'item_key' => self::KEY, 'parent_key' => $ref->parent_key, 'sort_order' => $ref->sort_order + 1,
                    'external' => 0, 'is_custom' => 0, 'hidden' => (int) $ref->hidden, 'location' => $ref->location ?: 'sidebar',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        if (Schema::hasTable('translations') && Schema::hasColumn('translations', 'es')) {
            foreach (['Audit_Log' => ['Change history', 'Historial de cambios'], 'audit_log_view' => ['Change history', 'Historial de cambios']] as $key => [$en, $es]) {
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
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
