<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ScopesToCompany;
use App\Models\Setting;
use App\Services\DemoData\DemoModules;
use App\Services\DemoData\DemoRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Demo Data generator (System Settings -> Demo Data), per company.
 *
 * One generator per module (App\Services\DemoData\Modules\*): core plus every
 * business module the company has switched ON under Modules. A module is
 * skipped when the company already has REAL data in it, or already has its
 * demo examples ("exclude example if exists"). Logic lives in DemoRunner
 * (also used when creating a company with demo data).
 *
 * Every generated row is tagged in demo_records (company, module, table, id),
 * so reset() removes exactly this company's demo rows -- newest first, which
 * deletes child rows before their parents -- and never touches real data.
 */
class DemoDataController extends Controller
{
    use ScopesToCompany;

    /** Demo modules whose examples depend on the business type (App\Support\BusinessTypes). */
    public const TYPE_MODULES = ['accounting', 'regular_expenses', 'assets', 'portfolio', 'website'];

    public function status(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $companyId = (int) $this->resolveActingCompanyId($request);
        $ctx = DemoRunner::context($companyId, (int) $request->user('api')->id, false);
        $flags = DemoRunner::moduleFlags($companyId);

        $modules = [];
        foreach (DemoModules::all() as $module) {
            $modules[] = DemoRunner::status($ctx, $module, $flags);
        }

        return response()->json([
            'company_id' => $companyId,
            'company_name' => DB::table('companies')->where('id', $companyId)->value('name'),
            'modules' => $modules,
            'demo_total' => DB::table('demo_records')->where('company_id', $companyId)->count(),
            // What the company does -- accounting / expenses examples follow it.
            'business_type' => \App\Support\BusinessTypes::of($companyId),
            'business_types' => collect(\App\Support\BusinessTypes::LABELS)->map(fn ($label, $value) => compact('value', 'label'))->values(),
            'type_modules' => self::TYPE_MODULES,
        ]);
    }

    public function generate(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['string', 'max:40'],
            'business_type' => ['nullable', 'string', 'in:'.implode(',', array_keys(\App\Support\BusinessTypes::LABELS))],
            // HRM / Payroll history: are the staff paid by hours or commission?
            'pay_type' => ['nullable', 'in:hourly,commission'],
        ]);
        $companyId = (int) $this->resolveActingCompanyId($request);
        $userId = (int) $request->user('api')->id;
        $modules = $data['modules'];
        $replaced = [];
        // Another business type (user's rule, 2026-10-06): the examples that follow
        // it are removed first and generated again for the new type -- never two
        // sets side by side (e.g. landscaping photos next to salon ones).
        if (! empty($data['business_type'])) {
            $old = \App\Support\BusinessTypes::of($companyId);
            if ($data['business_type'] !== $old) {
                foreach (self::TYPE_MODULES as $key) {
                    if (! DB::table('demo_records')->where('company_id', $companyId)->where('module', $key)->exists()) {
                        continue;
                    }
                    try {
                        DemoRunner::reset($companyId, $userId, $key);
                        $replaced[] = $key;
                    } catch (\Illuminate\Database\QueryException $e) {
                        report($e); // referenced by real data: left as it is, reported below as skipped
                    }
                }
                $modules = array_values(array_unique(array_merge($modules, $replaced)));
            }
            // Saved on the company: the next run (and other features) know it.
            \App\Support\BusinessTypes::set($companyId, $data['business_type']);
        }

        $results = DemoRunner::run($companyId, $userId, $modules, $data['pay_type'] ?? null);

        return response()->json(['success' => true, 'results' => $results, 'replaced' => $replaced]);
    }

    public function reset(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $companyId = (int) $this->resolveActingCompanyId($request);

        try {
            $deleted = DemoRunner::reset($companyId, (int) $request->user('api')->id, $request->input('module') ?: null);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return response()->json([
                'message' => 'Reset failed: some demo records are referenced by real data (e.g. a real sale using a demo product). Remove those references first.',
            ], 409);
        }

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }
}
