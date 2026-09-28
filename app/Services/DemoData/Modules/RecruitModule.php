<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Recruits & Jobs examples: job categories -> job postings, candidates,
 * applications across the pipeline stages, and interviews.
 */
class RecruitModule extends DemoModule
{
    public function key(): string
    {
        return 'recruit';
    }

    public function label(): string
    {
        return 'Recruits & Jobs';
    }

    public function dataTables(): array
    {
        return ['recruit_jobs', 'recruit_candidates', 'recruit_applications'];
    }

    public function requiredTables(): array
    {
        return ['recruit_job_categories', 'recruit_jobs', 'recruit_candidates', 'recruit_applications', 'recruit_interviews'];
    }

    public function generate(DemoContext $ctx): array
    {
        $counts = [];

        $categories = [];
        foreach ([
            'Sales' => 'Customer-facing sales roles',
            'Operations' => 'Warehouse, logistics and back-office',
            'Technology' => 'IT and software roles',
        ] as $name => $desc) {
            $categories[$name] = $ctx->insert('recruit_job_categories', ['name' => $name, 'description' => $desc, 'is_active' => 1]);
        }
        $counts['Job categories'] = count($categories);

        // Optional link to one of this company's departments (lookup only).
        $deptId = DB::table('departments')->where('company_id', $ctx->companyId)->whereNull('deleted_at')->value('id');

        $jobs = [];
        foreach ([
            ['Sales Associate', 'Sales', 'full_time', 'entry', 'open', 2, 28000, 35000],
            ['Store Manager', 'Sales', 'full_time', 'manager', 'open', 1, 52000, 65000],
            ['Warehouse Assistant', 'Operations', 'part_time', 'entry', 'open', 3, 18000, 24000],
            ['Junior Web Developer', 'Technology', 'remote', 'mid', 'draft', 1, 45000, 60000],
        ] as [$title, $cat, $type, $level, $status, $vac, $min, $max]) {
            $jobs[] = $ctx->insert('recruit_jobs', [
                'title' => $title,
                'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
                'category_id' => $categories[$cat],
                'department_id' => $deptId,
                'job_type' => $type,
                'location' => $type === 'remote' ? 'Remote' : 'Main office',
                'description' => "We are looking for a motivated $title to join our growing team.",
                'requirements' => "- Relevant experience\n- Good communication skills\n- Team player",
                'benefits' => "- Health insurance\n- Paid time off\n- Employee discount",
                'salary_min' => $min,
                'salary_max' => $max,
                'currency' => 'USD',
                'vacancies' => $vac,
                'status' => $status,
                'experience_level' => $level,
                'deadline' => Carbon::now()->addDays(random_int(15, 45))->toDateString(),
                'created_by' => $ctx->userId,
            ]);
        }
        $counts['Job postings'] = count($jobs);

        $candidates = [];
        $used = [];
        for ($i = 0; $i < 6; $i++) {
            do {
                [$first, $last] = $ctx->personName();
            } while (isset($used[$first.$last]));
            $used[$first.$last] = true;
            $candidates[] = $ctx->insert('recruit_candidates', [
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower($first.'.'.$last).'.'.Str::lower(Str::random(6)).'@example.com',
                'phone' => '+1 555 '.random_int(100, 999).' '.random_int(1000, 9999),
                'city' => $ctx->pick(['Miami', 'Orlando', 'Tampa', 'Jacksonville']),
                'country' => 'United States',
                'current_company' => $ctx->pick(['Retail Co.', 'Acme Logistics', 'Freelance', null]),
                'current_position' => $ctx->pick(['Sales Clerk', 'Team Lead', 'Assistant', 'Developer']),
                'expected_salary' => $ctx->pick([30000, 38000, 45000, 55000]),
                'experience_years' => random_int(0, 10),
                'skills' => $ctx->pick(['Customer service, POS', 'Inventory, forklift', 'PHP, Vue.js', 'Team management']),
                'source' => $ctx->pick(['website', 'referral', 'linkedin', 'job_board', 'walk_in']),
            ]);
        }
        $counts['Candidates'] = count($candidates);

        // Applications: candidate i -> job (i mod jobs), unique per job+candidate.
        $stages = ['applied', 'screening', 'shortlisted', 'interview', 'offered', 'rejected'];
        $apps = [];
        foreach ($candidates as $i => $candId) {
            $stage = $stages[$i];
            $applied = $ctx->randomDate(30);
            $apps[$stage] = $ctx->insert('recruit_applications', [
                'job_id' => $jobs[$i % 3], // only the open postings
                'candidate_id' => $candId,
                'stage' => $stage,
                'applied_date' => $applied->toDateString(),
                'cover_letter' => 'I am excited to apply for this position and believe my experience is a great fit.',
                'rating' => $stage === 'applied' ? null : random_int(2, 5),
                'reviewed_by' => $stage === 'applied' ? null : $ctx->userId,
                'reviewed_at' => $stage === 'applied' ? null : $applied->copy()->addDays(2),
            ]);
        }
        $counts['Applications'] = count($apps);

        foreach ([
            ['interview', 'in_person', Carbon::now()->addDays(3)->setTime(10, 0), 'scheduled'],
            ['offered', 'video', Carbon::now()->subDays(5)->setTime(14, 30), 'completed'],
            ['rejected', 'phone', Carbon::now()->subDays(8)->setTime(11, 0), 'completed'],
        ] as [$stage, $type, $when, $status]) {
            $ctx->insert('recruit_interviews', [
                'application_id' => $apps[$stage],
                'type' => $type,
                'scheduled_at' => $when,
                'duration_minutes' => 45,
                'location' => $type === 'in_person' ? 'Main office - Meeting room' : null,
                'meeting_link' => $type === 'video' ? 'https://meet.example.com/interview' : null,
                'interviewer_id' => $ctx->userId,
                'status' => $status,
                'rating' => $status === 'completed' ? ($stage === 'rejected' ? 2 : 4) : null,
                'feedback' => $status !== 'completed' ? null
                    : ($stage === 'rejected' ? 'Not enough hands-on experience for this role.' : 'Strong communication, good culture fit.'),
            ]);
        }
        $counts['Interviews'] = 3;

        return $counts;
    }
}
