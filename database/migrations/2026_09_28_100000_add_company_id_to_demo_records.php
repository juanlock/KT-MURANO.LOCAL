<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Demo Data per company: every tagged demo row now records which company it
 * was generated for, so status/reset only ever see/remove that company's
 * demo rows (multicompany rule). record_type is the row's TABLE name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('demo_records', function (Blueprint $table) {
            if (! Schema::hasColumn('demo_records', 'company_id')) {
                $table->unsignedInteger('company_id')->nullable()->index()->after('id');
            }
            if (! Schema::hasColumn('demo_records', 'module')) {
                $table->string('module', 40)->nullable()->index()->after('company_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('demo_records', function (Blueprint $table) {
            foreach (['company_id', 'module'] as $c) {
                if (Schema::hasColumn('demo_records', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
