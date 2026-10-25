<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forms dashboard (2026-10-24): per form and day, how many times it was SEEN
 * (page / iframe opened), STARTED (the visitor typed in it) and SENT -- the
 * funnel behind the conversion rate (App\Support\FormStats). Counting starts
 * with this migration: earlier days only have submissions (form_submissions).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('form_stats_daily')) {
            Schema::create('form_stats_daily', function (Blueprint $t) {
                $t->id();
                $t->unsignedInteger('company_id')->index();
                $t->unsignedBigInteger('form_id');
                $t->date('day');
                $t->unsignedInteger('views')->default(0);
                $t->unsignedInteger('starts')->default(0);
                $t->unsignedInteger('submissions')->default(0);
                $t->unique(['form_id', 'day']);
                $t->index('day');
            });
        }
        if (Schema::hasTable('translations')) {
            foreach ([
                'Forms_Dashboard' => ['Dashboard', 'Panel'],
            ] as $key => [$en, $es]) {
                if (! DB::table('translations')->where('key', $key)->exists()) {
                    DB::table('translations')->insert(['key' => $key, 'is_default' => 1, 'en' => $en, 'es' => $es, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('form_stats_daily');
    }
};
