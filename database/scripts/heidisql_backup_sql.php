<?php

// Writes KT-TOOLS/SQL/heidisql_backup_4_files.sql: the same 4-file backup as
// Protocol Copia BD, made from HeidiSQL instead of mysqldump (user's request,
// 2026-10-06):
//   file 1 (main)       -- HeidiSQL's own Tools -> Export database as SQL,
//                          every table except office_emails (instructions in the file);
//   office_emails 1/2/3 -- SQL in the file: office_emails cut in three id ranges
//                          of about the same size (same rule as Copia BD), each
//                          written as INSERT statements with SELECT ... INTO OUTFILE.
// The office_emails column list and CREATE TABLE are read from the database
// when this runs, so re-run it after a migration changes office_emails:
//   php artisan tinker --execute="require '<absolute-path-to-repo>\database\scripts\heidisql_backup_sql.php';"
// Optional before the require: $outFolder = 'D:/Backups/' (a folder the MariaDB
// service can write to; default C:/Users/Public/Documents/).

use Illuminate\Support\Facades\DB;

$db = (string) DB::connection()->getDatabaseName();
$outFolder = isset($outFolder) ? rtrim(str_replace('\\', '/', (string) $outFolder), '/').'/' : 'C:/Users/Public/Documents/';

$cols = array_map(fn ($c) => $c->Field, DB::select('SHOW COLUMNS FROM office_emails'));
$create = (array) DB::selectOne('SHOW CREATE TABLE office_emails');
$create = $create['Create Table'] ?? array_values($create)[1];
// Portable collations, as Copia BD does (MariaDB 11 / MySQL 8-only ones -> utf8mb4_unicode_ci).
$create = preg_replace('/\b(utf8mb[34])_(?:uca1400|0900)_[a-z_]+\b/', '$1_unicode_ci', $create);

// A PHP string -> a SQL string literal (single-quoted, escaped).
$lit = fn (string $s) => "'".str_replace(['\\', "'", "\n", "\r"], ['\\\\', "\\'", '\\n', ''], $s)."'";

$colList = implode(', ', array_map(fn ($c) => "`$c`", $cols));
// QUOTE() leaves line breaks raw; clients normalize \r\n when they read a file,
// so CR / LF inside values are written as the escapes \r / \n (each INSERT on
// one line, the text restored byte for byte).
$values = implode(", ',', ", array_map(fn ($c) => 'REPLACE(REPLACE(QUOTE(`'.$c.'`), CHAR(13), \'\\\\r\'), CHAR(10), \'\\\\n\')', $cols));
$insert = "CONCAT('INSERT INTO `office_emails` ($colList) VALUES (', $values, ');')";

$head = "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n";
$part = function (int $n, string $where, string $title, bool $withTable) use ($lit, $insert, $head, $create, $outFolder) {
    $first = "-- Murano backup (HeidiSQL), office_emails part {$n} of 3: {$title}\n".$head
        .($withTable ? "DROP TABLE IF EXISTS `office_emails`;\n{$create};\n" : '');
    $file = "{$outFolder}dbmurano_office_emails_{$n}.sql";

    return <<<SQL
-- ---------------------------------------------------------------------------
-- office_emails part {$n} of 3 -> {$file}
-- ---------------------------------------------------------------------------
SELECT `line` FROM (
    SELECT 0 AS `k`, {$lit($first)} AS `line`
    UNION ALL
    SELECT `id` AS `k`, {$insert} AS `line` FROM `office_emails` WHERE {$where}
) AS `part{$n}`
ORDER BY `k`
INTO OUTFILE '{$file}'
CHARACTER SET utf8mb4
FIELDS ESCAPED BY ''
LINES TERMINATED BY '\\n';

SQL;
};

$generated = now()->format('Y-m-d h:i A');
$parts = $part(1, '`id` <= @cut_a', 'id <= cut A (creates the table)', true)
    .$part(2, '`id` > @cut_a AND `id` <= @cut_b', 'cut A < id <= cut B', false)
    .$part(3, '`id` > @cut_b', 'id > cut B', false);

$sql = <<<SQL
-- ===========================================================================
-- Murano backup in 4 files from HeidiSQL  (same split as Protocol Copia BD)
-- Generated {$generated} from database `{$db}` by database/scripts/heidisql_backup_sql.php
-- (re-generate it after a migration changes the office_emails table).
--
--   File 1  backup db.sql                 every table EXCEPT office_emails
--   File 2  dbmurano_office_emails_1.sql  office_emails: the table + first third
--   File 3  dbmurano_office_emails_2.sql  office_emails: second third
--   File 4  dbmurano_office_emails_3.sql  office_emails: last third
--   (thirds of about the same SIZE, not the same number of emails)
--
-- RESTORE (phpMyAdmin or HeidiSQL, same database): File 1, then 2, 3, 4 in order.
-- ===========================================================================
--
-- FILE 1 -- HeidiSQL menu (SQL alone cannot dump a whole database):
--   1. Connect, click database `{$db}` on the left.
--   2. Tools -> Export database as SQL.
--   3. Tick every table EXCEPT `office_emails` (keep routines / triggers ticked).
--   4. Database(s): (nothing -- no CREATE DATABASE, so it imports into any database)
--      Table(s):    DROP + CREATE
--      Data:        INSERT
--      Output:      Single .sql file -> e.g. D:\\Backups\\backup db.sql
--   5. Export.
--
-- FILES 2-4 -- run THIS file: open it in a HeidiSQL query tab and press F9
-- (Execute all). It writes three .sql files to:
--      {$outFolder}
--   * The FOLDER is written by the MariaDB server itself (SELECT ... INTO OUTFILE),
--     so it must be a folder the MariaDB service may write to (this PC:
--     C:/Users/Public/Documents/ works; the project folder does not). To use
--     another one, replace {$outFolder} below (3 places).
--   * INTO OUTFILE never overwrites: delete or move the previous
--     dbmurano_office_emails_*.sql files from that folder first.
--   * Needs the FILE privilege (an admin user such as root has it).
-- ===========================================================================

USE `{$db}`;

-- The two cut points: office_emails split by cumulative size (body + html +
-- attachments + snippet bytes, +500 per row), like Protocol Copia BD.
SET @total := (SELECT SUM(COALESCE(LENGTH(`body_html`),0) + COALESCE(LENGTH(`body_text`),0) + COALESCE(LENGTH(`attachments`),0) + COALESCE(LENGTH(`snippet`),0) + 500) FROM `office_emails`);
SELECT
    COALESCE(MIN(CASE WHEN `run` >= @total / 3     THEN `id` END), 0),
    COALESCE(MIN(CASE WHEN `run` >= @total * 2 / 3 THEN `id` END), 0)
INTO @cut_a, @cut_b
FROM (
    SELECT `id`, SUM(COALESCE(LENGTH(`body_html`),0) + COALESCE(LENGTH(`body_text`),0) + COALESCE(LENGTH(`attachments`),0) + COALESCE(LENGTH(`snippet`),0) + 500) OVER (ORDER BY `id`) AS `run`
    FROM `office_emails`
) AS `sizes`;
SELECT @cut_a AS `cut A (last id of part 1)`, @cut_b AS `cut B (last id of part 2)`;

{$parts}
-- Done: check the three files in {$outFolder}
SQL;

$target = base_path('KT-TOOLS'.DIRECTORY_SEPARATOR.'SQL'.DIRECTORY_SEPARATOR.'heidisql_backup_4_files.sql');
file_put_contents($target, $sql);
echo "Written: {$target} (".round(filesize($target) / 1024, 1)." KB, ".count($cols)." office_emails columns, output folder {$outFolder})\n";
