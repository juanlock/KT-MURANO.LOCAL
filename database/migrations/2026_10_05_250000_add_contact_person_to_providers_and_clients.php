<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact person (name / email / phone) on suppliers and customers -- e.g. the
 * account manager of a supplier, the person to talk to at a business
 * customer. Suppliers also get state / zip (customers already have them).
 * All nullable; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['providers', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                if ($table === 'providers') {
                    if (! Schema::hasColumn('providers', 'state')) {
                        $t->string('state', 100)->nullable()->after('city');
                    }
                    if (! Schema::hasColumn('providers', 'zip')) {
                        $t->string('zip', 20)->nullable()->after('state');
                    }
                }
                if (! Schema::hasColumn($table, 'contact_name')) {
                    $t->string('contact_name', 190)->nullable()->after('phone');
                }
                if (! Schema::hasColumn($table, 'contact_email')) {
                    $t->string('contact_email', 190)->nullable()->after('contact_name');
                }
                if (! Schema::hasColumn($table, 'contact_phone')) {
                    $t->string('contact_phone', 50)->nullable()->after('contact_email');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['providers', 'clients'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $cols = array_values(array_filter(
                    array_merge(['contact_name', 'contact_email', 'contact_phone'], $table === 'providers' ? ['state', 'zip'] : []),
                    fn ($c) => Schema::hasColumn($table, $c)
                ));
                if ($cols) {
                    $t->dropColumn($cols);
                }
            });
        }
    }
};
