<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a "version" track number to kt_logs -- one row = one version bump.
 * Format "MM.mm" (e.g. "01.01", "01.02", ... "01.99", "02.00", ...),
 * starting at 01.01. This isn't a native MySQL AUTO_INCREMENT (that only
 * does plain integers) -- it's maintained by a BEFORE INSERT trigger so
 * every future row gets the next version regardless of how it's inserted
 * (Eloquent, raw DB::table()->insert(), a bulk multi-row insert, or a
 * direct SQL client) -- no reliance on remembering to compute it in
 * whatever script does the logging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kt_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('kt_logs', 'version')) {
                $table->string('version', 10)->nullable()->after('id');
            }
        });

        // Backfill every existing row in creation order: 01.01, 01.02, ...
        $ids = DB::table('kt_logs')->orderBy('id')->pluck('id');
        $counter = 100; // first increment below makes the very first row 01.01
        foreach ($ids as $id) {
            $counter++;
            $version = sprintf('%02d.%02d', intdiv($counter, 100), $counter % 100);
            DB::table('kt_logs')->where('id', $id)->update(['version' => $version]);
        }

        DB::unprepared('DROP TRIGGER IF EXISTS kt_logs_before_insert_version');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER kt_logs_before_insert_version
            BEFORE INSERT ON kt_logs
            FOR EACH ROW
            BEGIN
                DECLARE last_counter INT;
                IF NEW.version IS NULL OR NEW.version = '' THEN
                    SELECT COALESCE(
                        MAX(CAST(SUBSTRING_INDEX(version, '.', 1) AS UNSIGNED) * 100
                            + CAST(SUBSTRING_INDEX(version, '.', -1) AS UNSIGNED)),
                        100
                    ) INTO last_counter FROM kt_logs;
                    SET last_counter = last_counter + 1;
                    SET NEW.version = CONCAT(
                        LPAD(FLOOR(last_counter / 100), 2, '0'), '.',
                        LPAD(last_counter MOD 100, 2, '0')
                    );
                END IF;
            END
            SQL
        );
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS kt_logs_before_insert_version');
        Schema::table('kt_logs', function (Blueprint $table) {
            if (Schema::hasColumn('kt_logs', 'version')) {
                $table->dropColumn('version');
            }
        });
    }
};
