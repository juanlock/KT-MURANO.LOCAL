<?php

namespace App\Services\DemoData;

/**
 * One module's example-data generator (System Settings -> Demo Data).
 *
 * Contract:
 *  - key(): the module flag key from resources/src/config/modules.js
 *    ('hrm', 'projects', ...), or 'core' for the always-on core.
 *  - dataTables(): the module's main tables. If the company already has REAL
 *    rows in any of them, the module is skipped ("exclude example if exists").
 *  - generate(): create a small, realistic set of examples (a handful of rows
 *    per table, linked to each other) using ONLY $ctx->insert(), and return
 *    [label => count] of what was created. Runs inside a DB transaction.
 *  - purge(): optional cleanup of untaggable rows (pivots) before the generic
 *    newest-first delete of tagged rows.
 */
abstract class DemoModule
{
    abstract public function key(): string;

    abstract public function label(): string;

    /** @return string[] */
    abstract public function dataTables(): array;

    /** @return array<string,int> */
    abstract public function generate(DemoContext $ctx): array;

    public function purge(DemoContext $ctx): void
    {
    }

    /** Tables this module needs to exist at all (default: its data tables). */
    public function requiredTables(): array
    {
        return $this->dataTables();
    }
}
