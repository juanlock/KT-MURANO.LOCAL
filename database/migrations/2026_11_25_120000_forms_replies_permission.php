<?php

use App\Support\TranslationKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Forms dashboard -> suggested automatic replies (user's rule, 2026-11-25): the business user confirms the text and turns
 * it on in a window, never by editing the form (that stays with the IT Admin, forms_manage). Permission forms_replies for
 * the IT Admin, Manager, Director and Operaciones roles of every company. Safe to run twice; by name.
 */
return new class extends Migration
{
    private const PERM = 'forms_replies';

    private const TEXTS = [
        'Forms_Replies' => ['Forms: approve the automatic replies', 'Formularios: aprobar las respuestas automáticas'],
        'forms_replies' => ['Forms: approve the automatic replies', 'Formularios: aprobar las respuestas automáticas'],
    ];

    public function up(): void
    {
        if (! DB::table('permissions')->where('name', self::PERM)->exists()) {
            DB::table('permissions')->insert(['name' => self::PERM, 'label' => self::TEXTS[self::PERM][0], 'created_at' => now(), 'updated_at' => now()]);
        }
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        $roles = DB::table('roles')->whereIn('name', ['IT Admin', 'Manager', 'Director', 'Operaciones'])->whereNull('deleted_at')->pluck('id');
        foreach ($roles as $rid) {
            if (! DB::table('permission_role')->where('role_id', $rid)->where('permission_id', $pid)->exists()) {
                DB::table('permission_role')->insert(['role_id' => $rid, 'permission_id' => $pid]);
            }
        }
        foreach (self::TEXTS as $key => [$en, $es]) {
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

    public function down(): void
    {
        $pid = DB::table('permissions')->where('name', self::PERM)->value('id');
        if ($pid) {
            DB::table('permission_role')->where('permission_id', $pid)->delete();
            DB::table('permissions')->where('id', $pid)->delete();
        }
    }
};
