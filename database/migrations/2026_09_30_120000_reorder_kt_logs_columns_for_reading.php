<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reorders kt_logs' columns so a row reads top-down when browsed (phpMyAdmin,
 * exports): the short "what/when/state" fields first, then the request and
 * its answer, then the technical detail, bookkeeping last.
 *
 *   id, version, log_date, location, status, category, company_context, summary
 *   request_user, claude_understand, done
 *   changes_detail, files_modified, actions, chat_transcript
 *   claude_user, created_at, updated_at, deleted_at
 *
 * Cosmetic only: each column keeps its exact definition, no data changes,
 * and the version trigger / model / scripts address columns by name.
 */
return new class extends Migration
{
    // [column, definition] in the new order (definitions as currently in the DB).
    private const ORDER = [
        ['version', 'VARCHAR(10) NULL DEFAULT NULL'],
        ['log_date', 'DATE NOT NULL'],
        ['location', 'VARCHAR(191) NOT NULL'],
        ['status', "VARCHAR(191) NOT NULL DEFAULT 'Completed'"],
        ['category', 'VARCHAR(191) NULL DEFAULT NULL'],
        ['company_context', 'VARCHAR(191) NULL DEFAULT NULL'],
        ['summary', 'VARCHAR(191) NULL DEFAULT NULL'],
        ['request_user', 'LONGTEXT NOT NULL'],
        ['claude_understand', 'LONGTEXT NOT NULL'],
        ['done', 'LONGTEXT NULL DEFAULT NULL'],
        ['changes_detail', 'LONGTEXT NULL DEFAULT NULL'],
        ['files_modified', 'LONGTEXT NULL DEFAULT NULL'],
        ['actions', 'LONGTEXT NULL DEFAULT NULL'],
        ['chat_transcript', 'LONGTEXT NOT NULL'],
        ['claude_user', 'VARCHAR(191) NOT NULL'],
        ['created_at', 'TIMESTAMP NULL DEFAULT NULL'],
        ['updated_at', 'TIMESTAMP NULL DEFAULT NULL'],
        ['deleted_at', 'TIMESTAMP NULL DEFAULT NULL'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('kt_logs')) {
            return;
        }
        $after = 'id';
        $parts = [];
        foreach (self::ORDER as [$col, $def]) {
            $parts[] = "MODIFY COLUMN `{$col}` {$def} AFTER `{$after}`";
            $after = $col;
        }
        DB::statement('ALTER TABLE `kt_logs` '.implode(', ', $parts));
    }

    public function down(): void
    {
        // Original order (2026_09_22_120000 + later additions).
        $original = ['version', 'log_date', 'claude_user', 'location', 'request_user', 'claude_understand',
            'chat_transcript', 'files_modified', 'actions', 'changes_detail', 'summary', 'category', 'status',
            'done', 'company_context', 'created_at', 'updated_at', 'deleted_at'];
        $defs = array_column(self::ORDER, 1, 0);
        $after = 'id';
        $parts = [];
        foreach ($original as $col) {
            $parts[] = "MODIFY COLUMN `{$col}` {$defs[$col]} AFTER `{$after}`";
            $after = $col;
        }
        DB::statement('ALTER TABLE `kt_logs` '.implode(', ', $parts));
    }
};
