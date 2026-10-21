<?php

namespace App\Http\Controllers;

use App\Models\Contract;
use App\Models\ContractAttachment;
use App\Models\ContractComment;
use App\Models\ContractNote;
use App\Models\ContractRenewal;
use App\Models\ContractTask;
use App\Models\ContractTemplate;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Setting;
use App\utils\helpers;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDF;

/**
 * HRM > Employee Contracts. Employees ONLY (customer contracts are Sales >
 * Sales Contracts, SalesContractController -- split 2026-10-02). Scoped to
 * the acting company (Admin: own; SuperAdmin: the selected one).
 */
class ContractController extends Controller
{
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    /** Employee contracts of the acting company. */
    private function scoped(Request $request)
    {
        return Contract::where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee');
    }

    /**
     * Employee contracts the LIST / dashboard reads: superadmin/AdminSystem =
     * every company (2026-10-21, listCompanyId()); writes keep scoped().
     */
    private function listScoped(Request $request)
    {
        return $this->scopeListToCompany(Contract::query(), $request)->where('party_type', 'employee');
    }

    /** Child routes (attachments, comments, tasks, notes): the contract must be ours. */
    private function ownContract(Request $request, $contractId): void
    {
        abort_unless($this->scoped($request)->whereKey($contractId)->exists(), 404);
    }

    /** The acting company's employees, for the form. */
    private function companyEmployees(Request $request)
    {
        return Employee::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))
            ->orderBy('firstname')->get(['id', 'firstname', 'lastname']);
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);

        $perPage = $request->limit ?? 10;
        $pageStart = \Request::get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField ?? 'id';
        $dir = $request->SortType ?? 'desc';
        $helpers = new helpers;

        $param = [0 => 'like', 1 => '=', 2 => '=', 3 => '=', 4 => '='];
        $columns = [0 => 'contract_number', 1 => 'employee_id', 2 => 'status', 3 => 'type', 4 => 'project_id'];

        $query = $this->listScoped($request)->with('employee', 'project')->whereNull('deleted_at');
        $query = $helpers->filter($query, $columns, $param, $request);
        $query->where(function ($q) use ($request) {
            $q->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where('contract_number', 'LIKE', "%{$s}%")
                    ->orWhere('subject', 'LIKE', "%{$s}%")
                    ->orWhereHas('employee', fn ($e) => $e->where('firstname', 'LIKE', "%{$s}%")->orWhere('lastname', 'LIKE', "%{$s}%"));
            });
        });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $contracts = $query->offset($offSet)->limit($perPage)->orderBy($order, $dir)->get();

        // superadmin/AdminSystem: each row says whose it is (2026-10-21).
        $companyNames = \App\Models\Company::whereIn('id', $contracts->pluck('company_id')->unique())->pluck('name', 'id');

        $data = [];
        foreach ($contracts as $c) {
            $employeeName = $c->employee ? trim($c->employee->firstname . ' ' . $c->employee->lastname) : '';
            $data[] = [
                'id' => $c->id,
                'company_name' => (int) $c->company_id === \App\Models\Setting::TEMPLATE_COMPANY_ID ? 'AdminSystem' : ($companyNames[$c->company_id] ?? null),
                'contract_number' => $c->contract_number,
                'subject' => $c->subject,
                'employee_id' => $c->employee_id,
                'employee_name' => $employeeName,
                'party_name' => $employeeName,
                'project_id' => $c->project_id,
                'project_name' => $c->project ? $c->project->title : null,
                'value' => (float) $c->value,
                'type' => $c->type,
                'start_date' => $c->start_date,
                'end_date' => $c->end_date,
                'status' => $c->status,
            ];
        }

        $projects = Project::whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'title']);

        return response()->json([
            'totalRows' => $totalRows,
            'contracts' => $data,
            // Employee filter options follow the list's company.
            'employees' => $this->scopeListToCompany(Employee::whereNull('deleted_at'), $request)
                ->orderBy('firstname')->get(['id', 'firstname', 'lastname']),
            'projects' => $projects,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Contract::class);

        $projects = Project::whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'title']);

        return response()->json([
            'employees' => $this->companyEmployees($request),
            'projects' => $projects,
            'next_contract_number' => Contract::generateContractNumber(),
        ]);
    }

    /** The employee must belong to the acting company. */
    private function assertEmployee(Request $request): void
    {
        abort_unless(Employee::whereKey($request->employee_id)->whereNull('deleted_at')
            ->where('company_id', $this->resolveActingCompanyId($request))->exists(), 422, 'That employee does not belong to this company.');
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Contract::class);

        $request->validate([
            'employee_id' => 'required|integer',
            'subject' => 'required|string|max:255',
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'required|in:draft,active,expired,cancelled',
        ], $this->contractDateMessages($request), $this->contractAttributes());
        $this->assertEmployee($request);

        $contract_number = $request->contract_number ?: Contract::generateContractNumber();

        Contract::create([
            'company_id' => $this->resolveActingCompanyId($request),
            'contract_number' => $contract_number,
            'party_type' => 'employee',
            'client_id' => null,
            'employee_id' => $request->employee_id,
            'project_id' => $request->project_id,
            'subject' => $request->subject,
            'value' => $request->value,
            'type' => $request->type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'description' => $request->description,
            'status' => $request->status,
        ]);

        return response()->json(['success' => true]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);

        $contract = Contract::with(['employee', 'project', 'attachments', 'comments.user', 'renewals', 'contractTasks', 'notes.user'])
            ->whereNull('deleted_at')
            ->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);

        $employeeName = $contract->employee ? trim($contract->employee->firstname . ' ' . $contract->employee->lastname) : '';

        $item = [
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'subject' => $contract->subject,
            'employee_id' => $contract->employee_id,
            'employee_name' => $employeeName,
            'party_name' => $employeeName,
            'project_id' => $contract->project_id,
            'project_name' => $contract->project ? $contract->project->title : null,
            'value' => (float) $contract->value,
            'type' => $contract->type,
            'start_date' => $contract->start_date,
            'end_date' => $contract->end_date,
            'description' => $contract->description,
            'status' => $contract->status,
            'signer_name' => $contract->signer_name,
            'signed_at' => $contract->signed_at ? $contract->signed_at->toIso8601String() : null,
            'signed_ip' => $contract->signed_ip,
            'created_at' => $contract->created_at->toIso8601String(),
            'updated_at' => $contract->updated_at->toIso8601String(),
            'attachments' => $contract->attachments->map(fn ($a) => ['id' => $a->id, 'file_name' => $a->file_name, 'file_path' => $a->file_path]),
            'comments' => $contract->comments->map(fn ($c) => [
                'id' => $c->id, 'body' => $c->body, 'user_id' => $c->user_id,
                'user_name' => $c->user ? $c->user->username : '', 'created_at' => $c->created_at->toIso8601String(),
            ]),
            'renewals' => $contract->renewals->map(fn ($r) => [
                'id' => $r->id, 'renewal_date' => $r->renewal_date, 'new_end_date' => $r->new_end_date, 'notes' => $r->notes,
            ]),
            'tasks' => $contract->contractTasks->map(fn ($t) => [
                'id' => $t->id, 'title' => $t->title, 'due_date' => $t->due_date, 'status' => $t->status, 'description' => $t->description,
            ]),
            'notes' => $contract->notes->map(fn ($n) => [
                'id' => $n->id, 'content' => $n->content, 'user_name' => $n->user ? $n->user->username : '', 'created_at' => $n->created_at->toIso8601String(),
            ]),
        ];

        return response()->json(['contract' => $item]);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);

        $contract = Contract::with('employee', 'project')->whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $projects = Project::whereNull('deleted_at')->orderBy('id', 'desc')->get(['id', 'title']);

        return response()->json([
            'contract' => $contract,
            'employees' => $this->companyEmployees($request),
            'projects' => $projects,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);

        $request->validate([
            'employee_id' => 'required|integer',
            'subject' => 'required|string|max:255',
            'value' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'required|in:draft,active,expired,cancelled',
        ], $this->contractDateMessages($request), $this->contractAttributes());
        $this->assertEmployee($request);

        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $contract->update([
            'employee_id' => $request->employee_id,
            'project_id' => $request->project_id,
            'subject' => $request->subject,
            'value' => $request->value,
            'type' => $request->type,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'description' => $request->description,
            'status' => $request->status,
            'signer_name' => $request->signer_name ?? $contract->signer_name,
            'signed_at' => $request->signed_at ?? $contract->signed_at,
            'signed_ip' => $request->signed_ip ?? $contract->signed_ip,
        ]);

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Contract::class);

        $this->scoped($request)->whereId($id)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function delete_by_selection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Contract::class);

        $ids = $request->ids ?? [];
        $this->scoped($request)->whereIn('id', $ids)->update(['deleted_at' => Carbon::now()]);

        return response()->json(['success' => true]);
    }

    public function dashboard(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);

        $now = Carbon::now()->toDateString();
        $aboutToExpireDays = 30;
        $aboutToExpireEnd = Carbon::now()->addDays($aboutToExpireDays)->toDateString();
        $recentlyAddedDays = 7;
        $recentlyAddedStart = Carbon::now()->subDays($recentlyAddedDays)->toDateString();

        $countActive = $this->listScoped($request)->whereNull('deleted_at')->where('status', 'active')
            ->where('start_date', '<=', $now)->where('end_date', '>=', $now)->count();
        $countExpired = $this->listScoped($request)->whereNull('deleted_at')->where('end_date', '<', $now)->count();
        $countAboutToExpire = $this->listScoped($request)->whereNull('deleted_at')
            ->where('status', 'active')
            ->whereBetween('end_date', [$now, $aboutToExpireEnd])->count();
        $countRecentlyAdded = $this->listScoped($request)->whereNull('deleted_at')->where('created_at', '>=', $recentlyAddedStart)->count();
        $countTrash = $this->listScoped($request)->onlyTrashed()->count();

        $byType = $this->listScoped($request)->whereNull('deleted_at')
            ->select('type', DB::raw('count(*) as total'))
            ->groupBy('type')
            ->get()
            ->map(fn ($r) => ['type' => $r->type ?: 'Other', 'count' => $r->total]);

        $valueByType = $this->listScoped($request)->whereNull('deleted_at')
            ->select('type', DB::raw('SUM(value) as total_value'))
            ->groupBy('type')
            ->get()
            ->map(fn ($r) => ['type' => $r->type ?: 'Other', 'value' => (float) $r->total_value]);

        return response()->json([
            'count_active' => $countActive,
            'count_expired' => $countExpired,
            'count_about_to_expire' => $countAboutToExpire,
            'count_recently_added' => $countRecentlyAdded,
            'count_trash' => $countTrash,
            'charts' => [
                'by_type' => $byType,
                'value_by_type' => $valueByType,
            ],
        ]);
    }

    public function pdf(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);

        $contract = Contract::with('employee', 'project')->whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);

        $employeeName = $contract->employee ? trim($contract->employee->firstname . ' ' . $contract->employee->lastname) : '-';

        $contractData = [
            'contract_number' => $contract->contract_number,
            'subject' => $contract->subject,
            'party_type' => 'employee',
            'party_label' => 'Employee',
            'party_name' => $employeeName,
            'customer_name' => $employeeName,
            'project_name' => $contract->project ? $contract->project->title : null,
            'value_formatted' => number_format($contract->value, 2),
            'type' => $contract->type ?? '-',
            'start_date' => $contract->start_date,
            'end_date' => $contract->end_date,
            'status' => $contract->status,
            'description' => $contract->description,
            'signer_name' => $contract->signer_name,
            'signed_at' => $contract->signed_at ? $contract->signed_at->format('F j, Y H:i') : null,
            'signed_ip' => $contract->signed_ip,
        ];

        $setting = Setting::whereNull('deleted_at')->first();
        $Html = view('pdf.contract_pdf', ['contract' => $contractData, 'setting' => $setting])->render();

        if (class_exists(\ArPHP\I18N\Arabic::class)) {
            try {
                $arabic = new \ArPHP\I18N\Arabic;
                $p = $arabic->arIdentify($Html);
                for ($i = count($p) - 1; $i >= 0; $i -= 2) {
                    $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
                    $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
                }
            } catch (\Throwable $e) {
                // ignore
            }
        }

        $pdf = PDF::loadHTML($Html);
        $filename = 'Contract_' . $contract->contract_number . '.pdf';
        return $request->boolean('preview')
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    /** Render a template merged with a contract's data as a PDF (preview or download). */
    public function templatePdf(Request $request, $contractId, $templateId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);

        $contract = Contract::with('project', 'employee')->whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($contractId);
        $template = ContractTemplate::findOrFail($templateId);
        $body = $template->render($contract);

        $title = e($template->name) . ' — ' . e($contract->contract_number);
        $Html = '<!doctype html><html><head><meta charset="UTF-8"><title>' . $title . '</title>'
            . '<style>body{font-family:DejaVu Sans, Arial, sans-serif;font-size:12px;color:#222;line-height:1.5;padding:24px;}'
            . 'h1,h2,h3{margin:1em 0 0.4em;}p{margin:0 0 0.6em;}table{border-collapse:collapse;}'
            . 'table td,table th{border:1px solid #ccc;padding:6px;}</style></head><body>'
            . $body
            . '</body></html>';

        $pdf = PDF::loadHTML($Html);
        $safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $template->name);
        $filename = 'Template_' . $safeName . '_' . $contract->contract_number . '.pdf';
        return $request->boolean('preview')
            ? $pdf->stream($filename)
            : $pdf->download($filename);
    }

    public function mergeFields(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);

        $fields = [
            ['key' => '{contract_number}', 'label' => 'Contract Number'],
            ['key' => '{employee_name}', 'label' => 'Employee Name'],
            ['key' => '{employee_email}', 'label' => 'Employee Email'],
            ['key' => '{employee_phone}', 'label' => 'Employee Phone'],
            ['key' => '{employee_address}', 'label' => 'Employee Address'],
            ['key' => '{project_name}', 'label' => 'Project Name'],
            ['key' => '{subject}', 'label' => 'Subject'],
            ['key' => '{contract_value}', 'label' => 'Contract Value (USD)'],
            ['key' => '{contract_type}', 'label' => 'Contract Type'],
            ['key' => '{start_date}', 'label' => 'Start Date'],
            ['key' => '{end_date}', 'label' => 'End Date'],
            ['key' => '{description}', 'label' => 'Description'],
            ['key' => '{status}', 'label' => 'Status'],
            ['key' => '{signer_name}', 'label' => 'Signer Name'],
            ['key' => '{signed_at}', 'label' => 'Signed Date'],
            ['key' => '{signed_ip}', 'label' => 'IP Address'],
        ];

        return response()->json(['merge_fields' => $fields]);
    }

    // ---------- Attachments ----------
    public function attachmentsIndex(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $list = $contract->attachments->map(fn ($a) => ['id' => $a->id, 'file_name' => $a->file_name, 'file_path' => $a->file_path]);
        return response()->json(['attachments' => $list]);
    }

    public function attachmentsStore(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $request->validate(['file' => 'required|file|max:10240']);
        $file = $request->file('file');
        // Project standard: public/files/company_{id}/contracts/{Y}/{m} (CompanyFiles, private: downloaded through attachmentsDownload).
        $path = \App\Support\CompanyFiles::putUpload((int) $contract->company_id, 'contracts', $file, true);
        ContractAttachment::create(['contract_id' => $id, 'file_name' => $file->getClientOriginalName(), 'file_path' => $path]);
        return response()->json(['success' => true, 'path' => $path]);
    }

    public function attachmentsDestroy(Request $request, $contractId, $attachmentId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        $this->ownContract($request, $contractId);
        $att = ContractAttachment::where('contract_id', $contractId)->findOrFail($attachmentId);
        @unlink($att->absolutePath());
        $att->delete();
        return response()->json(['success' => true]);
    }

    public function attachmentsDownload(Request $request, $contractId, $attachmentId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $this->ownContract($request, $contractId);
        $att = ContractAttachment::where('contract_id', $contractId)->findOrFail($attachmentId);
        $path = $att->absolutePath();
        if (!file_exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }
        return response()->download($path, $att->file_name);
    }

    // ---------- Comments ----------
    public function commentsIndex(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $list = $contract->comments()->with('user')->orderBy('created_at', 'desc')->get()
            ->map(fn ($c) => ['id' => $c->id, 'body' => $c->body, 'user_id' => $c->user_id, 'user_name' => $c->user ? $c->user->username : '', 'created_at' => $c->created_at->toIso8601String()]);
        return response()->json(['comments' => $list]);
    }

    public function commentsStore(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $request->validate(['body' => 'required|string|max:5000']);
        $c = ContractComment::create(['contract_id' => $id, 'user_id' => Auth::id(), 'body' => $request->body]);
        $c->load('user');
        return response()->json(['success' => true, 'comment' => ['id' => $c->id, 'body' => $c->body, 'user_name' => $c->user->username ?? '', 'created_at' => $c->created_at->toIso8601String()]]);
    }

    public function commentsDestroy(Request $request, $contractId, $commentId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        $this->ownContract($request, $contractId);
        ContractComment::where('contract_id', $contractId)->where('id', $commentId)->delete();
        return response()->json(['success' => true]);
    }

    // ---------- Renewals ----------
    public function renewalsIndex(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $list = $contract->renewals;
        return response()->json(['renewals' => $list]);
    }

    public function renewalsStore(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $request->validate(['renewal_date' => 'required|date', 'new_end_date' => 'required|date', 'notes' => 'nullable|string']);
        ContractRenewal::create([
            'contract_id' => $id,
            'renewal_date' => $request->renewal_date,
            'new_end_date' => $request->new_end_date,
            'notes' => $request->notes,
        ]);
        return response()->json(['success' => true]);
    }

    // ---------- Tasks ----------
    public function tasksIndex(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $list = $contract->contractTasks;
        return response()->json(['tasks' => $list]);
    }

    public function tasksStore(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $request->validate(['title' => 'required|string|max:255', 'due_date' => 'nullable|date', 'status' => 'nullable|in:pending,in_progress,completed,cancelled', 'description' => 'nullable|string']);
        $t = ContractTask::create([
            'contract_id' => $id,
            'title' => $request->title,
            'due_date' => $request->due_date,
            'status' => $request->status ?? 'pending',
            'description' => $request->description,
        ]);
        return response()->json(['success' => true, 'task' => $t]);
    }

    public function tasksUpdate(Request $request, $contractId, $taskId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        $this->ownContract($request, $contractId);
        $task = ContractTask::where('contract_id', $contractId)->findOrFail($taskId);
        $task->update($request->only(['title', 'due_date', 'status', 'description']));
        return response()->json(['success' => true]);
    }

    public function tasksDestroy(Request $request, $contractId, $taskId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        $this->ownContract($request, $contractId);
        ContractTask::where('contract_id', $contractId)->where('id', $taskId)->delete();
        return response()->json(['success' => true]);
    }

    // ---------- Notes ----------
    public function notesIndex(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $list = $contract->notes()->with('user')->orderBy('created_at', 'desc')->get()
            ->map(fn ($n) => ['id' => $n->id, 'content' => $n->content, 'user_name' => $n->user ? $n->user->username : '', 'created_at' => $n->created_at->toIso8601String()]);
        return response()->json(['notes' => $list]);
    }

    public function notesStore(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $contract = Contract::whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($id);
        $request->validate(['content' => 'required|string|max:10000']);
        $n = ContractNote::create(['contract_id' => $id, 'user_id' => Auth::id(), 'content' => $request->content]);
        $n->load('user');
        return response()->json(['success' => true, 'note' => ['id' => $n->id, 'content' => $n->content, 'user_name' => $n->user->username ?? '', 'created_at' => $n->created_at->toIso8601String()]]);
    }

    public function notesDestroy(Request $request, $contractId, $noteId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        $this->ownContract($request, $contractId);
        ContractNote::where('contract_id', $contractId)->where('id', $noteId)->delete();
        return response()->json(['success' => true]);
    }

    // ---------- Templates (global) ----------
    public function templatesIndex(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $list = ContractTemplate::orderBy('name')->get(['id', 'name', 'content']);
        return response()->json(['templates' => $list]);
    }

    public function templatesShow(Request $request, $templateId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $t = ContractTemplate::findOrFail($templateId);
        return response()->json(['template' => $t]);
    }

    public function templatesStore(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Contract::class);
        $request->validate(['name' => 'required|string|max:255', 'content' => 'nullable|string']);
        $t = ContractTemplate::create($request->only(['name', 'content']));
        return response()->json(['success' => true, 'template' => $t]);
    }

    public function templatesUpdate(Request $request, $templateId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Contract::class);
        $t = ContractTemplate::findOrFail($templateId);
        $t->update($request->only(['name', 'content']));
        return response()->json(['success' => true]);
    }

    public function templatesDestroy(Request $request, $templateId)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Contract::class);
        ContractTemplate::whereId($templateId)->delete();
        return response()->json(['success' => true]);
    }

    /** Render a template with contract merge fields (preview or export). */
    public function templateRender(Request $request, $contractId, $templateId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Contract::class);
        $contract = Contract::with('project', 'employee')->whereNull('deleted_at')->where('company_id', $this->resolveActingCompanyId($request))->where('party_type', 'employee')->findOrFail($contractId);
        $template = ContractTemplate::findOrFail($templateId);
        $html = $template->render($contract);
        return response()->json(['content' => $html]);
    }

    private function contractDateMessages(Request $request): array
    {
        $start = $request->input('start_date');
        $end = $request->input('end_date');
        $endVsStart = $start && $end
            ? "Finish date ({$end}) cannot be earlier than the start date ({$start})."
            : 'Finish date cannot be earlier than the start date.';

        return [
            'start_date.required' => 'Start date is required.',
            'start_date.date' => 'Start date is not a valid date.',
            'end_date.required' => 'Finish date is required.',
            'end_date.date' => 'Finish date is not a valid date.',
            'end_date.after_or_equal' => $endVsStart,
        ];
    }

    private function contractAttributes(): array
    {
        return [
            'start_date' => 'start date',
            'end_date' => 'finish date',
        ];
    }
}
