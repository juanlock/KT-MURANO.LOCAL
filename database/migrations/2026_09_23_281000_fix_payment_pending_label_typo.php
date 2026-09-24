<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correction: the requested label was "UnPaid", not "NoPaid".
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('translations')->where('key', 'PaymentPending')->update(['en' => 'UnPaid']);
    }

    public function down(): void
    {
        DB::table('translations')->where('key', 'PaymentPending')->update(['en' => 'NoPaid']);
    }
};
