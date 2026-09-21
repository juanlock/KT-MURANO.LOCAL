<?php

namespace App\Services\Updater;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Database dump and restore for the updater.
 *
 * Dump: tries the mysqldump binary first (fast, complete). When the binary
 * is unavailable (typical shared hosting) it falls back to a pure-PHP,
 * chunked dumper that can spread its work across multiple short HTTP
 * requests via a cursor persisted in the update state.
 *
 * Restore: tries the mysql binary, falling back to a chunked PHP executor
 * that replays the dump statement by statement from a byte offset.
 */
class DatabaseBackupService
{
    /** Rows per INSERT batch in the PHP dumper. */
    private const ROW_BATCH = 400;

    // ------------------------------------------------------------------ dump

    /**
     * Try to produce the full dump in one shot using mysqldump.
     * Returns true on success, false when the binary route is unusable.
     */
    public function dumpWithBinary(string $outputPath): bool
    {
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            return false; // disable_functions on shared hosting
        }
        $db = $this->connectionConfig();
        $bin = trim((string) env('DUMP_PATH'));
        if ($bin === '') {
            $bin = 'mysqldump';
        } elseif (strpos($bin, ' ') !== false && $bin[0] !== '"') {
            $bin = '"'.$bin.'"';
        }

        $cmd = $bin
            .' --user='.escapeshellarg($db['username'])
            .($db['password'] !== '' ? ' --password='.escapeshellarg($db['password']) : '')
            .' --host='.escapeshellarg($db['host'])
            .($db['port'] ? ' --port='.escapeshellarg((string) $db['port']) : '')
            .' --single-transaction --routines --triggers --add-drop-table'
            .' '.escapeshellarg($db['database'])
            .' > '.escapeshellarg($outputPath);

        $output = [];
        $ret = 1;
        try {
            @\exec($cmd.' 2>&1', $output, $ret);
        } catch (\Throwable $e) {
            return false;
        }
        if ($ret !== 0 || ! File::exists($outputPath) || File::size($outputPath) < 100) {
            @File::delete($outputPath);

            return false;
        }

        return true;
    }

    /**
     * Chunked PHP dump. $cursor keeps ['tables'=>[], 'views'=>[], 'table_index'=>int,
     * 'row_offset'=>int, 'header_done'=>bool, 'done'=>bool]. Call repeatedly until
     * $cursor['done'] is true; $shouldStop() is consulted between batches.
     */
    public function dumpChunkPhp(string $outputPath, array &$cursor, callable $shouldStop): void
    {
        if (empty($cursor['header_done'])) {
            $tables = [];
            $views = [];
            foreach (DB::select('SHOW FULL TABLES') as $row) {
                $vals = array_values((array) $row);
                if (($vals[1] ?? '') === 'VIEW') {
                    $views[] = $vals[0];
                } else {
                    $tables[] = $vals[0];
                }
            }
            $header = "-- Stocky updater PHP dump\n"
                ."-- Generated: ".date('Y-m-d H:i:s')."\n"
                ."SET NAMES utf8mb4;\n"
                ."SET FOREIGN_KEY_CHECKS=0;\n"
                ."SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n";
            File::put($outputPath, $header);
            $cursor = [
                'tables' => $tables,
                'views' => $views,
                'table_index' => 0,
                'row_offset' => 0,
                'header_done' => true,
                'done' => false,
            ];
        }

        $out = fopen($outputPath, 'ab');
        if (! $out) {
            throw new \RuntimeException('Cannot open database dump file for writing');
        }

        try {
            while ($cursor['table_index'] < count($cursor['tables'])) {
                $table = $cursor['tables'][$cursor['table_index']];

                if ($cursor['row_offset'] === 0) {
                    $create = DB::select('SHOW CREATE TABLE `'.str_replace('`', '``', $table).'`');
                    $createSql = array_values((array) $create[0])[1] ?? null;
                    if (! $createSql) {
                        throw new \RuntimeException('Cannot read structure of table '.$table);
                    }
                    fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n{$createSql};\n\n");
                }

                $orderBy = $this->primaryKeyOrder($table);
                while (true) {
                    $rows = DB::table($table)
                        ->when($orderBy, fn ($q) => $q->orderByRaw($orderBy))
                        ->offset($cursor['row_offset'])
                        ->limit(self::ROW_BATCH)
                        ->get();
                    if ($rows->isEmpty()) {
                        break;
                    }
                    fwrite($out, $this->insertStatement($table, $rows));
                    $cursor['row_offset'] += $rows->count();
                    if ($rows->count() < self::ROW_BATCH) {
                        break;
                    }
                    if ($shouldStop()) {
                        return; // resume later at this row offset
                    }
                }

                $cursor['table_index']++;
                $cursor['row_offset'] = 0;
                if ($shouldStop()) {
                    return;
                }
            }

            foreach ($cursor['views'] as $view) {
                $create = DB::select('SHOW CREATE TABLE `'.str_replace('`', '``', $view).'`');
                $createSql = array_values((array) $create[0])[1] ?? null;
                if ($createSql) {
                    fwrite($out, "DROP VIEW IF EXISTS `{$view}`;\n{$createSql};\n\n");
                }
            }
            fwrite($out, "SET FOREIGN_KEY_CHECKS=1;\n-- DUMP COMPLETE\n");
            $cursor['done'] = true;
        } finally {
            fclose($out);
        }
    }

    /** Basic sanity check that a dump file exists and finished writing. */
    public function verifyDump(string $path, bool $phpDump): bool
    {
        if (! File::exists($path) || File::size($path) < 100) {
            return false;
        }
        if ($phpDump) {
            $tail = $this->tail($path, 200);

            return strpos($tail, '-- DUMP COMPLETE') !== false;
        }
        // mysqldump ends with "-- Dump completed" unless --skip-comments
        $tail = $this->tail($path, 400);

        return strpos($tail, 'Dump completed') !== false || strpos($tail, 'SET ') !== false;
    }

    // --------------------------------------------------------------- restore

    public function restoreWithBinary(string $sqlPath): bool
    {
        if (! function_exists('exec') || ! function_exists('escapeshellarg')) {
            return false; // disable_functions on shared hosting
        }
        $db = $this->connectionConfig();
        $bin = trim((string) env('MYSQL_PATH'));
        if ($bin === '') {
            $bin = 'mysql';
        } elseif (strpos($bin, ' ') !== false && $bin[0] !== '"') {
            $bin = '"'.$bin.'"';
        }

        $cmd = $bin
            .' --user='.escapeshellarg($db['username'])
            .($db['password'] !== '' ? ' --password='.escapeshellarg($db['password']) : '')
            .' --host='.escapeshellarg($db['host'])
            .($db['port'] ? ' --port='.escapeshellarg((string) $db['port']) : '')
            .' '.escapeshellarg($db['database'])
            .' < '.escapeshellarg($sqlPath);

        $output = [];
        $ret = 1;
        try {
            @\exec($cmd.' 2>&1', $output, $ret);
        } catch (\Throwable $e) {
            return false;
        }

        return $ret === 0;
    }

    /** Drop every table and view in the current database. */
    public function dropAllTables(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (DB::select('SHOW FULL TABLES') as $row) {
                $vals = array_values((array) $row);
                $name = str_replace('`', '``', $vals[0]);
                if (($vals[1] ?? '') === 'VIEW') {
                    DB::statement("DROP VIEW IF EXISTS `{$name}`");
                } else {
                    DB::statement("DROP TABLE IF EXISTS `{$name}`");
                }
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Names of the tables (and view stubs) a dump file creates — a linear
     * scan for CREATE TABLE statements.
     */
    public function dumpTableNames(string $sqlPath): array
    {
        $names = [];
        $handle = @fopen($sqlPath, 'rb');
        if (! $handle) {
            return $names;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^(?:\/\*M?!\d+\s*)?CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([^`\s(]+)`?/i', $line, $m)) {
                    $names[$m[1]] = true;
                }
            }
        } finally {
            fclose($handle);
        }

        return array_keys($names);
    }

    /**
     * After a dump has been replayed, remove the base tables it does not
     * contain (typically tables created by the migrations of the failed
     * update). Views are left alone. Needed because tables are no longer
     * wiped before the replay — see UpdateManager::restoreDatabase().
     */
    public function dropTablesNotInDump(string $sqlPath): array
    {
        $keep = array_fill_keys($this->dumpTableNames($sqlPath), true);
        if (empty($keep)) {
            return []; // unreadable dump — never wipe on a guess
        }
        $dropped = [];
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach (DB::select('SHOW FULL TABLES') as $row) {
                $vals = array_values((array) $row);
                if (($vals[1] ?? '') === 'VIEW' || isset($keep[$vals[0]])) {
                    continue;
                }
                DB::statement('DROP TABLE IF EXISTS `'.str_replace('`', '``', $vals[0]).'`');
                $dropped[] = $vals[0];
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $dropped;
    }

    /**
     * Chunked PHP restore: executes statements from $sqlPath starting at
     * $cursor['offset']. Handles mysqldump conditional comments and
     * DELIMITER blocks (triggers/routines). Sets $cursor['done'] when the
     * whole file has been replayed.
     *
     * Yielding ($shouldStop) is only honoured at a table boundary — right
     * before the next DROP TABLE/VIEW — so a table is always dropped and
     * refilled within one request. The admin's next step request has to
     * authenticate against users/roles/permissions, which must therefore
     * never be left missing between requests.
     */
    public function restoreChunkPhp(string $sqlPath, array &$cursor, callable $shouldStop): void
    {
        $handle = fopen($sqlPath, 'rb');
        if (! $handle) {
            throw new \RuntimeException('Cannot open SQL dump for restore');
        }
        try {
            fseek($handle, (int) ($cursor['offset'] ?? 0));
            $delimiter = $cursor['delimiter'] ?? ';';
            $buffer = '';

            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            while (($line = fgets($handle)) !== false) {
                $trim = trim($line);
                if ($buffer === '' && ($trim === '' || strpos($trim, '--') === 0)) {
                    $cursor['offset'] = ftell($handle);
                    continue;
                }
                if ($buffer === '' && stripos($trim, 'DELIMITER ') === 0) {
                    $delimiter = trim(substr($trim, 10));
                    $cursor['delimiter'] = $delimiter;
                    $cursor['offset'] = ftell($handle);
                    continue;
                }

                $buffer .= $line;
                $trimmedBuffer = rtrim($buffer);
                if (substr($trimmedBuffer, -strlen($delimiter)) === $delimiter) {
                    $statement = trim(substr($trimmedBuffer, 0, -strlen($delimiter)));
                    if ($statement !== '' && ! $this->isSessionRestoreStatement($statement)) {
                        DB::unprepared($statement);
                    }
                    $buffer = '';
                    $cursor['offset'] = ftell($handle);
                    if ($shouldStop() && $this->atTableBoundary($handle)) {
                        return;
                    }
                }
            }

            // Trailing statement without terminator
            if (trim($buffer) !== '' && strpos(trim($buffer), '--') !== 0) {
                DB::unprepared($buffer);
            }
            $cursor['offset'] = ftell($handle);
            $cursor['done'] = true;
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        } finally {
            fclose($handle);
        }

        $cursor['dropped_extra'] = $this->dropTablesNotInDump($sqlPath);
    }

    /**
     * "SET <system var> = @<user var>" statements restore session settings
     * mysqldump saved earlier in the same file (@OLD_TIME_ZONE,
     * @saved_cs_client, ...). Replayed on a different connection the user
     * variable is NULL and MySQL refuses the assignment (error 1231) — and
     * a per-request connection is exactly what a chunked replay uses. They
     * only matter within a single client session, so they are skipped.
     */
    private function isSessionRestoreStatement(string $statement): bool
    {
        return (bool) preg_match('/^(?:\/\*M?!\d+\s*)?SET\s+(?:SESSION\s+)?[A-Za-z_]+\s*=\s*@[A-Za-z_]/i', $statement);
    }

    /**
     * Peek ahead (without consuming) — true when the next statement starts
     * a new table/view section or the file has ended.
     */
    private function atTableBoundary($handle): bool
    {
        $pos = ftell($handle);
        $boundary = true;
        while (($line = fgets($handle)) !== false) {
            $trim = trim($line);
            if ($trim === '' || strpos($trim, '--') === 0) {
                continue;
            }
            $boundary = (bool) preg_match('/^(?:\/\*M?!\d+\s*)?DROP\s+(?:TABLE|VIEW)\b/i', $trim);
            break;
        }
        fseek($handle, $pos);

        return $boundary;
    }

    // --------------------------------------------------------------- helpers

    private function connectionConfig(): array
    {
        $connection = config('database.default');
        $db = config('database.connections.'.$connection, []);

        return [
            'username' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            'host' => (string) ($db['host'] ?? '127.0.0.1'),
            'port' => $db['port'] ?? null,
            'database' => (string) ($db['database'] ?? ''),
        ];
    }

    private function primaryKeyOrder(string $table): ?string
    {
        try {
            $keys = DB::select("SHOW KEYS FROM `".str_replace('`', '``', $table)."` WHERE Key_name = 'PRIMARY'");
            if (empty($keys)) {
                return null;
            }
            $cols = array_map(fn ($k) => '`'.str_replace('`', '``', $k->Column_name).'`', $keys);

            return implode(', ', $cols);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function insertStatement(string $table, $rows): string
    {
        $pdo = DB::getPdo();
        $values = [];
        foreach ($rows as $row) {
            $vals = [];
            foreach ((array) $row as $value) {
                if ($value === null) {
                    $vals[] = 'NULL';
                } elseif (is_int($value) || is_float($value)) {
                    $vals[] = (string) $value;
                } else {
                    $vals[] = $pdo->quote((string) $value);
                }
            }
            $values[] = '('.implode(',', $vals).')';
        }
        if (empty($values)) {
            return '';
        }

        return "INSERT INTO `{$table}` VALUES\n".implode(",\n", $values).";\n";
    }

    private function tail(string $path, int $bytes): string
    {
        $size = File::size($path);
        $handle = fopen($path, 'rb');
        if (! $handle) {
            return '';
        }
        fseek($handle, max(0, $size - $bytes));
        $tail = (string) fread($handle, $bytes);
        fclose($handle);

        return $tail;
    }
}
