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

    /**
     * Business Modules switches this demo belongs to (2026-10-21): it is offered
     * only when ANY of them is on for the company. Default: its own key
     * (hospital, school, portfolio...). Demos of a part of another module
     * override it (holidays / payroll -> hrm, regular expenses -> accounting).
     *
     * @return string[]
     */
    public function moduleFlags(): array
    {
        return [$this->key()];
    }

    /**
     * SuperAdmin "Delete real data" (2026-10-21, for test companies): what to
     * delete for ONE company, children first. Each step is one of
     *   ['table' => t]                          rows of t with that company_id
     *   ['table' => t, 'parent' => [col, p]]    rows of t whose col points to that company's rows of p (lines without company_id)
     *   ['journal' => type, 'of' => t]          accounting entries (+ lines) posted for that company's rows of t
     * Empty = the module doesn't offer it.
     *
     * @return array<int,array>
     */
    public function wipePlan(): array
    {
        return [];
    }
}
