<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Project members see the whole project (user's rule, 2026-11-22: "que todos los miembros del equipo puedan ver todos los
 * tabs como el jefe de proyecto... solo puedan editar sus tareas asignadas"). Projects are their own module and a team can
 * mix companies, so a member -- whatever their company, role or modules -- reads every tab of THAT project (overview,
 * objectives, stages, status, timeline, calendar, report, list, members, documents, hours) through the same endpoints,
 * limited to that project; writing stays with the project's editors, and a member writes only on the tasks assigned to
 * them (ProjectTeamController::canWorkOnTask).
 */
class ProjectMembers
{
    /** Is this login a member of the project (one of its employee records is on the team)? */
    public static function isMember(?int $userId, ?int $projectId): bool
    {
        if (! $userId || ! $projectId) {
            return false;
        }

        return DB::table('employee_project as ep')->join('employees as e', 'e.id', '=', 'ep.employee_id')
            ->where('ep.project_id', $projectId)->where('e.user_id', $userId)->whereNull('e.deleted_at')->exists();
    }

    /** The project a list request is about (?project_id=) when the signed-in login is a member of it, else null. */
    public static function memberProject(Request $request): ?int
    {
        $pid = (int) $request->input('project_id');

        return $pid && self::isMember(optional($request->user('api'))->id, $pid) ? $pid : null;
    }
}
