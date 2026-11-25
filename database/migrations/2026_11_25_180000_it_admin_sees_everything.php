<?php

use Illuminate\Database\Migrations\Migration;

/**
 * A company's IT Admin = that company's SuperAdmin (user's rule, 2026-11-25): every dashboard tab and every permission
 * the company's other roles hold (App\Support\ItAdminAccess, which also runs after every migrate). Safe to run twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        \App\Support\ItAdminAccess::sync();
    }

    public function down(): void
    {
        // Grants are not taken back.
    }
};
