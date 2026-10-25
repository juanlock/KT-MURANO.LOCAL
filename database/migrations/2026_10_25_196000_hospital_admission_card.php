<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stay keeps the guarantee card given at the window (2026-10-25): type and
 * last 4 digits only (never the number). With insurance and / or a card the
 * account is closed and paid at discharge (HospitalAccount::settleAtDischarge).
 * Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('admissions', 'card_last4')) {
            Schema::table('admissions', function (Blueprint $t) {
                $t->string('card_type', 20)->nullable()->after('authorization_status');
                $t->string('card_last4', 4)->nullable()->after('card_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('admissions', 'card_last4')) {
            Schema::table('admissions', fn (Blueprint $t) => $t->dropColumn(['card_type', 'card_last4']));
        }
    }
};
