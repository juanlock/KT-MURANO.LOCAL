<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Multicompany for the Projects workspace (2026-11-19): tasks, milestones and time logs hang from a project, so they are
 * reachable only through a project of the list company (null = every company for superadmin/AdminSystem).
 */
trait ScopesProjects
{
    use ScopesToCompany;

    private ?array $scopedProjectIdsCache = null;

    private bool $scopedProjectIdsDone = false;

    /** Project ids the request may see; null = no restriction (superadmin/AdminSystem). */
    protected function projectIdsInScope(Request $request): ?array
    {
        if (! $this->scopedProjectIdsDone) {
            $cid = $this->listCompanyId($request);
            $this->scopedProjectIdsCache = $cid === null ? null
                : Project::where('company_id', $cid)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $this->scopedProjectIdsDone = true;
        }

        return $this->scopedProjectIdsCache;
    }

    /** Narrows a query on a table with a project column to the projects in scope. */
    protected function onlyScopedProjects($query, Request $request, string $column = 'project_id')
    {
        $ids = $this->projectIdsInScope($request);
        if ($ids !== null) {
            $query->whereIn($column, $ids ?: [0]);
        }

        return $query;
    }

    /** 404 unless the project is in scope (a write or a read of one record). */
    protected function abortUnlessProjectInScope(Request $request, $projectId): void
    {
        $ids = $this->projectIdsInScope($request);
        abort_unless($ids === null || in_array((int) $projectId, $ids, true), 404);
    }
}
