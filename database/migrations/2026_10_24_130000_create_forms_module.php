<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forms module (2026-10-24): Murano's own form builder -- written from scratch
 * (no Easy Forms code; only its DATA is imported, `php artisan forms:import-easyforms`).
 * A company builds forms (contact, complaints book, quotes...) and puts them on
 * its website / store / any site with an iframe or a script tag.
 *
 *  forms              company_id, name, public_key (embed id), status, fields (JSON
 *                     schema), settings (JSON: texts, confirmation, emails, spam,
 *                     allowed sites, look), next_number (submission numbering)
 *  form_submissions   form_id, number, data (JSON {field key: value}), ip, browser,
 *                     page, is_read
 * Files of "file" fields: public/files/company_{id}/forms/ (private).
 * Permissions: forms_view (forms + submissions), forms_manage (build / delete).
 * Business Module "forms": on for every company (missing key = on).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('forms')) {
            Schema::create('forms', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->string('name', 150);
                $t->string('public_key', 24)->unique();
                $t->boolean('status')->default(true);
                $t->json('fields')->nullable();
                $t->json('settings')->nullable();
                $t->unsignedInteger('next_number')->default(1);
                $t->string('legacy_source', 20)->nullable();
                $t->unsignedInteger('legacy_id')->nullable();
                $t->integer('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['legacy_source', 'legacy_id']);
            });
        }
        if (! Schema::hasTable('form_submissions')) {
            Schema::create('form_submissions', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('form_id')->index();
                $t->unsignedInteger('number')->default(0);
                $t->json('data')->nullable();
                $t->boolean('is_read')->default(false);
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->string('page_url', 500)->nullable();
                $t->unsignedInteger('legacy_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
                $t->index(['form_id', 'number']);
            });
        }

        // Permissions (by name), granted to the roles that manage the website / settings.
        $perms = [
            'forms_view' => 'Forms: see forms and their submissions',
            'forms_manage' => 'Forms: build, publish and delete forms and submissions',
        ];
        $ids = [];
        foreach ($perms as $name => $label) {
            $id = DB::table('permissions')->where('name', $name)->value('id');
            if (! $id) {
                $row = ['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()];
                if (Schema::hasColumn('permissions', 'description')) {
                    $row['description'] = $label;
                }
                $id = DB::table('permissions')->insertGetId($row);
            }
            $ids[] = $id;
        }
        $managers = DB::table('permissions')->whereIn('name', ['Store_settings_view', 'setting_system'])->pluck('id');
        $roles = DB::table('permission_role')->whereIn('permission_id', $managers)->distinct()->pluck('role_id');
        foreach ($roles as $rid) {
            foreach ($ids as $pid) {
                if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                    DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
                }
            }
        }

        // Menu / permission labels (English + Spanish), by key, only when missing.
        if (Schema::hasTable('translations')) {
            foreach ([
                'Forms_Module' => ['Forms', 'Formularios'],
                'Forms_All' => ['All forms', 'Todos los formularios'],
                'Forms_Submissions' => ['Submissions', 'Envíos'],
                'Forms_View' => ['See forms and submissions', 'Ver formularios y envíos'],
                'Forms_Manage' => ['Build and manage forms', 'Crear y administrar formularios'],
            ] as $key => [$en, $es]) {
                if (! DB::table('translations')->where('key', $key)->exists()) {
                    DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        \App\Support\SuperAdminAccess::sync();
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('forms');
        $ids = DB::table('permissions')->whereIn('name', ['forms_view', 'forms_manage'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
