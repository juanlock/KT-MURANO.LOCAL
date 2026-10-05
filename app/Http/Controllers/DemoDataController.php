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
        ]);
    }

    public function generate(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Setting::class);
        $data = $request->validate([
            'modules' => ['required', 'array', 'min:1'],
            'modules.*' => ['string', 'max:40'],
            'business_type' => ['nullable', 'string', 'in:'.implode(',', array_keys(\App\Support\BusinessTypes::LABELS))],
        ]);
        $companyId = (int) $this->resolveActingCompanyId($request);
        // Saved on the company: the next run (and other features) know it.
        if (! empty($data['business_type'])) {
            \App\Support\BusinessTypes::set($companyId, $data['business_type']);
        }

        $results = DemoRunner::run($companyId, (int) $request->user('api')->id, $data['modules']);

        return response()->json(['success' => true, 'results' => $results]);
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
