<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The unique index (locale, key) was case-INSENSITIVE (utf8mb4 CI), so
     * 'warehouse' and 'Warehouse' collapsed into one row on seed — while the
     * SPA's vue-i18n lookup is case-SENSITIVE, guaranteeing misses for the
     * casing that lost. Binary collation lets both casings exist, matching
     * the flat locale files in database/seeders/translations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE `translations` MODIFY `key` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        // Reverting can fail if rows differing only by case exist; remove the
        // later duplicates first so the CI unique index can be rebuilt.
        DB::statement('DELETE t1 FROM `translations` t1 JOIN `translations` t2 ON t1.locale = t2.locale AND LOWER(t1.`key`) = LOWER(t2.`key`) AND t1.id > t2.id');
        DB::statement('ALTER TABLE `translations` MODIFY `key` VARCHAR(191) CHARACTER SET utf8mb4 NOT NULL');
    }
};
