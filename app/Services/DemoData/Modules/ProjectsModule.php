<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Projects & Tasks examples: projects (for the company's clients) -> tasks,
 * milestones and timesheet entries. Assignments use the employee_project /
 * employee_task pivots (no id, so they're removed in purge()).
 */
class ProjectsModule extends DemoModule
{
    public function key(): string
    {
        return 'projects';
    }

    public function label(): string
    {
        return 'Projects & Tasks';
    }

    public function dataTables(): array
    {
        return ['projects', 'tasks'];
    }

    public function requiredTables(): array
    {
        return ['projects', 'tasks', 'project_milestones', 'project_time_logs', 'employee_project', 'employee_task'];
    }

    public function purge(DemoContext $ctx): void
    {
        if ($ids = $ctx->demoIds('tasks')) {
            DB::table('employee_task')->whereIn('task_id', $ids)->delete();
        }
        if ($ids = $ctx->demoIds('projects')) {
            DB::table('employee_project')->whereIn('project_id', $ids)->delete();
        }
    }

    public function generate(DemoContext $ctx): array
    {
        $counts = [];

        // Clients: the company's own (prefer non walk-in); create one if none.
        $clients = DB::table('clients')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
            ->where('name', '!=', 'Walk-in Customer')->orderBy('id')->limit(3)->pluck('id')->all();
        if (! $clients) {
            $clients = DB::table('clients')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
                ->orderBy('id')->limit(1)->pluck('id')->all();
        }
        if (! $clients) {
            $clients[] = $ctx->insert('clients', [
                'name' => 'Acme Corporation',
                'code' => (int) DB::table('clients')->max('code') + 1,
                'email' => 'contact.'.random_int(1000, 9999).'@acme.example.com',
                'city' => 'Miami',
                'country' => 'United States',
            ]);
            $counts['Clients'] = 1;
        }

        // Employees of this company (HRM/core demo ones or real ones) for assignments.
        $employees = DB::table('employees')->where('company_id', $ctx->companyId)->whereNull('deleted_at')
            ->orderBy('id')->limit(5)->get(['id', 'hourly_rate']);
        $empIds = $employees->pluck('id')->map(fn ($v) => (int) $v)->all();
        $rates = $employees->pluck('hourly_rate', 'id')->all();

        $projects = [
            ['Website Redesign', 'progress', -40, 50, 'Refresh of the public website and online store.'],
            ['New Branch Opening', 'not_started', 10, 100, 'Fit-out and launch of the new branch.'],
            ['ERP Data Migration', 'completed', -120, -20, 'Move legacy records into the new system.'],
        ];
        $taskTemplates = [
            ['Gather requirements', 'completed', 'high', 100],
            ['Design mockups', 'progress', 'medium', 60],
            ['Implementation', 'not_started', 'urgent', 0],
        ];
        $milestoneTemplates = [['Kick-off', 0.1], ['Phase 1 delivered', 0.5], ['Go-live', 1.0]];

        $counts += ['Projects' => 0, 'Tasks' => 0, 'Milestones' => 0, 'Time logs' => 0];
        foreach ($projects as $p => [$title, $status, $startOff, $endOff, $desc]) {
            $start = Carbon::now()->addDays($startOff)->startOfDay();
            $end = Carbon::now()->addDays($endOff)->startOfDay();
            $projectId = $ctx->insert('projects', [
                'title' => $title,
                'client_id' => $clients[$p % count($clients)],
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'description' => $desc,
                'status' => $status,
            ]);
            $counts['Projects']++;
            if ($empIds) {
                DB::table('employee_project')->insert(array_map(
                    fn ($e) => ['employee_id' => $e, 'project_id' => $projectId],
                    array_slice($empIds, 0, min(3, count($empIds)))
                ));
            }

            $span = max(1, $start->diffInDays($end));
            $taskIds = [];
            foreach ($taskTemplates as $t => [$tTitle, $tStatus, $priority, $progress]) {
                if ($status === 'completed') {
                    [$tStatus, $progress] = ['completed', 100];
                } elseif ($status === 'not_started') {
                    [$tStatus, $progress] = ['not_started', 0];
                }
                $tStart = $start->copy()->addDays((int) ($span * $t / 3));
                $tEnd = $start->copy()->addDays((int) ($span * ($t + 1) / 3));
                $taskId = $ctx->insert('tasks', [
                    'title' => $tTitle,
                    'project_id' => $projectId,
                    'start_date' => $tStart->toDateString(),
                    'end_date' => $tEnd->toDateString(),
                    'estimated_hour' => (string) $ctx->pick([8, 16, 24, 40]),
                    'task_progress' => (string) $progress,
                    'summary' => $tTitle.' for '.$title,
                    'description' => $tTitle.' for the '.$title.' project.',
                    'status' => $tStatus,
                    'priority' => $priority,
                ]);
                $taskIds[] = [$taskId, $tStart, $tStatus];
                $counts['Tasks']++;
                if ($empIds) {
                    DB::table('employee_task')->insert(['employee_id' => $empIds[$t % count($empIds)], 'task_id' => $taskId]);
                }
            }

            foreach ($milestoneTemplates as $m => [$mTitle, $frac]) {
                $due = $start->copy()->addDays((int) round($span * $frac));
                $mStatus = $status === 'completed' ? 'completed'
                    : ($due->isPast() ? ($status === 'progress' && $m === 0 ? 'completed' : 'delayed') : 'pending');
                if ($status === 'progress' && $m === 1 && ! $due->isPast()) {
                    $mStatus = 'in_progress';
                }
                $ctx->insert('project_milestones', [
                    'project_id' => $projectId,
                    'title' => $mTitle,
                    'description' => $mTitle.' milestone.',
                    'due_date' => $due->toDateString(),
                    'completed_on' => $mStatus === 'completed' ? $due->toDateString() : null,
                    'status' => $mStatus,
                    'progress' => $mStatus === 'completed' ? 100 : ($mStatus === 'in_progress' ? 50 : 0),
                    'budget' => $ctx->pick([1500, 2500, 5000]),
                    'position' => $m,
                    'created_by' => $ctx->userId,
                ]);
                $counts['Milestones']++;
            }

            // Timesheet entries on started tasks.
            foreach ($taskIds as [$taskId, $tStart, $tStatus]) {
                if ($tStatus === 'not_started') {
                    continue;
                }
                $empId = $empIds ? $ctx->pick($empIds) : null;
                $hours = $ctx->pick([2, 3.5, 4, 6, 8]);
                $rate = $empId && (float) ($rates[$empId] ?? 0) > 0 ? (float) $rates[$empId] : 25.0;
                $logDate = $tStart->copy()->addDays(1);
                if ($logDate->isFuture()) {
                    $logDate = Carbon::now()->subDay();
                }
                $ctx->insert('project_time_logs', [
                    'project_id' => $projectId,
                    'task_id' => $taskId,
                    'employee_id' => $empId,
                    'log_date' => $logDate->toDateString(),
                    'hours' => $hours,
                    'billable' => 1,
                    'hourly_rate' => $rate,
                    'amount' => round($hours * $rate, 2),
                    'description' => 'Work on task',
                    'created_by' => $ctx->userId,
                ]);
                $counts['Time logs']++;
            }
        }

        return $counts;
    }
}
