<?php

/*
 * phpMyAdmin-ready SQL (user's rules, 2026-10-01 / 2026-10-19): shared by every
 * database backup (Copia BD / Wake-up / Pa Llevar, and the production backups
 * taken before a deploy, migration or data push), so all of them import as-is
 * through phpMyAdmin -> Import into whatever database is selected:
 *  - MariaDB 11's first line "/*M!999999\- enable the sandbox mode" removed;
 *  - collations only MariaDB 11 (uca1400) or MySQL 8 (0900) know become
 *    utf8mb4_unicode_ci ("Unknown collation" otherwise);
 *  - DEFINER=`user`@`host` removed from triggers / routines / views (needs
 *    SUPER on shared hosting; without it they run as the importing user).
 * INSERT lines are never altered.
 *
 *   $sql = require __DIR__.'/protocol_sql_clean.php';
 *   $line = ($sql['line'])($line);                 // null = drop the line
 *   ($sql['file'])($dumpPath, $cleanPath, $header); // clean a finished dump
 *   ($sql['zip'])($cleanPath);                      // -> <name>.zip, .sql removed
 */

$line = function (string $line): ?string {
    if (str_starts_with($line, '/*M!999999\\- enable the sandbox mode')) {
        return null;
    }
    if (str_starts_with($line, 'INSERT INTO')) {
        return $line;
    }
    $line = preg_replace('/\b(utf8mb[34])_(?:uca1400|0900)_[a-z_]+\b/', '$1_unicode_ci', $line);
    $line = preg_replace('#/\*!50017 DEFINER=`[^`]*`@`[^`]*`\s*\*/\s*#', '', $line);
    $line = preg_replace('/\s*DEFINER\s*=\s*`[^`]*`@`[^`]*`/', '', $line);

    return $line;
};

$file = function (string $src, string $dst, string $header = '') use ($line): void {
    $in = fopen($src, 'r');
    $out = fopen($dst, 'w');
    if ($header !== '') {
        fwrite($out, $header);
    }
    while (($l = fgets($in)) !== false) {
        $c = $line($l);
        if ($c !== null) {
            fwrite($out, $c);
        }
    }
    fclose($in);
    fclose($out);
};

$zip = function (string $sqlPath): string {
    $zipPath = preg_replace('/\.sql$/', '', $sqlPath).'.zip';
    $z = new ZipArchive();
    $z->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $z->addFile($sqlPath, basename($sqlPath));
    $z->close();
    if (is_file($zipPath) && filesize($zipPath) > 0) {
        @unlink($sqlPath);
    }

    return $zipPath;
};

return ['line' => $line, 'file' => $file, 'zip' => $zip];
