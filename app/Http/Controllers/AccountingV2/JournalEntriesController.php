<?php

namespace App\Http\Controllers\AccountingV2;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountingV2\JournalEntry;
use App\Models\AccountingV2\JournalEntryLine;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NEW FEATURE - SAFE ADDITION
 */
class JournalEntriesController extends Controller
{
    // Multicompany: entries of the acting company (acc_journal_entries.company_id);
    // a SuperAdmin working as AdminSystem (999) sees every company's.
    use \App\Http\Controllers\Concerns\ScopesToCompany;

    private function allCompanies(Request $request): bool
    {
        return optional($request->user('api'))->type === 'SuperAdmin' && $this->resolveActingCompanyId($request) === 999;
    }

    private function entries(Request $request)
    {
        return JournalEntry::query()->when(! $this->allCompanies($request),
            fn ($q) => $q->where('acc_journal_entries.company_id', $this->resolveActingCompanyId($request)));
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'journal_entries', Account::class);

        if (! Schema::hasTable('acc_journal_entries')) {
            return response()->json(['data' => [], 'total' => 0]);
        }
        $dir = strtolower((string) $request->get('SortType')) === 'asc' ? 'asc' : 'desc';
        // superadmin/AdminSystem: every company (2026-10-21) -- the list follows
        // listCompanyId() (null = every company); show / post / edit keep entries().
        $listCid = $this->listCompanyId($request);
        $listEntries = fn () => JournalEntry::query()->when($listCid !== null, fn ($w) => $w->where('acc_journal_entries.company_id', $listCid));
        $q = $listEntries()->orderBy('acc_journal_entries.date', $dir)->orderBy('acc_journal_entries.id', $dir);
        if ($request->filled('status')) {
            $q->where('status', $request->get('status'));
        }
        if ($request->filled('reference_type')) {
            $q->where('reference_type', $request->get('reference_type'));
        }
        // Date range (PresetRangeFilter): from / to, or date_from / date_to.
        if ($from = $request->get('from') ?: $request->get('date_from')) {
            $q->where('date', '>=', $from);
        }
        if ($to = $request->get('to') ?: $request->get('date_to')) {
            $q->where('date', '<=', $to);
        }
        // An entry with a line on this account.
        if ($request->filled('coa_id')) {
            $q->whereIn('acc_journal_entries.id', JournalEntryLine::where('coa_id', (int) $request->coa_id)->select('journal_entry_id'));
        }
        // Entry amount (its total debits) between min and max.
        $amount = '(SELECT COALESCE(SUM(debit),0) FROM acc_journal_entry_lines WHERE journal_entry_id = acc_journal_entries.id)';
        if (is_numeric($request->get('amount_min'))) {
            $q->whereRaw("{$amount} >= ?", [(float) $request->amount_min]);
        }
        if (is_numeric($request->get('amount_max'))) {
            $q->whereRaw("{$amount} <= ?", [(float) $request->amount_max]);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(fn ($w) => $w->where('description', 'like', "%{$s}%")
                ->orWhere('acc_journal_entries.id', (int) ltrim($s, '#') ?: 0)
                ->orWhereIn('acc_journal_entries.id', JournalEntryLine::where('memo', 'like', "%{$s}%")->select('journal_entry_id')));
        }
        $items = $q->with('lines')->paginate($request->get('limit', 20));

        // Source of each entry ("Sale SL_0019 · Customer") and its amount.
        $refs = collect($items->items())->filter(fn ($e) => $e->reference_type && $e->reference_id && $e->reference_type !== 'manual')
            ->map(fn ($e) => ['type' => $e->reference_type, 'id' => $e->reference_id])->values()->all();
        $sources = \App\Services\AccountingV2\JournalSource::summaries($refs);
        $companies = $listCid === null
            ? DB::table('companies')->whereIn('id', collect($items->items())->pluck('company_id')->filter()->unique())->pluck('name', 'id')
            : collect();
        $items->getCollection()->transform(function ($e) use ($sources, $companies) {
            $e->source_label = $sources["{$e->reference_type}:{$e->reference_id}"] ?? null;
            $e->source_type_label = \App\Services\AccountingV2\JournalSource::label($e->reference_type);
            $e->amount = round((float) $e->lines->sum('debit'), 6);
            $e->company_name = $e->company_id == \App\Models\Setting::TEMPLATE_COMPANY_ID ? 'AdminSystem'
                : ($e->company_id ? ($companies[$e->company_id] ?? null) : null);

            return $e;
        });

        $payload = $items->toArray();
        // Filter lists: the source types used, the company's accounts.
        $payload['source_types'] = $listEntries()->distinct()->pluck('reference_type')->filter()->values()
            ->map(fn ($t) => ['value' => $t, 'label' => \App\Services\AccountingV2\JournalSource::label($t)]);
        $payload['all_companies'] = $listCid === null;

        return response()->json($payload);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'journal_entries', Account::class);
        $entry = $this->entries($request)->with('lines')->findOrFail($id);

        return response()->json($entry);
    }

    /** "Source" modal: the document the entry comes from (sale, payment, expense...). */
    public function source(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'journal_entries', Account::class);
        $entry = $this->entries($request)->findOrFail($id);
        $src = \App\Services\AccountingV2\JournalSource::describe($entry->reference_type, $entry->reference_id);

        return response()->json(['source' => $src, 'entry' => ['id' => $entry->id, 'date' => $entry->date, 'description' => $entry->description, 'reference_type' => $entry->reference_type]]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'journal_entries', Account::class);

        $this->validate($request, [
            'date' => 'required|date',
            'lines' => 'required|array|min:1',
            'lines.*.debit' => 'numeric',
            'lines.*.credit' => 'numeric',
        ]);

        $data = $request->only(['date', 'description', 'reference_type', 'reference_id']);
        $lines = $request->get('lines', []);
        $companyId = $this->resolveActingCompanyId($request);
        abort_if($this->allCompanies($request), 422, 'Pick a company in the header to add a journal entry.');
        $this->assertCompanyAccounts($lines, $companyId);
        // Allow saving unbalanced drafts; balance will be enforced on posting

        $entry = DB::transaction(function () use ($data, $lines, $companyId) {
            $entry = JournalEntry::create([
                // Manual entries belong to the acting company.
                'company_id' => $companyId,
                'date' => $data['date'],
                'description' => $data['description'] ?? null,
                'reference_type' => $data['reference_type'] ?? 'manual',
                'reference_id' => $data['reference_id'] ?? null,
                'status' => 'draft',
                'posted_at' => null,
            ]);
            foreach ($lines as $l) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'coa_id' => $l['coa_id'] ?? null,
                    'account_id' => $l['account_id'] ?? null,
                    'debit' => (float) ($l['debit'] ?? 0),
                    'credit' => (float) ($l['credit'] ?? 0),
                    'memo' => $l['memo'] ?? null,
                ]);
            }

            return $entry->load('lines');
        });

        return response()->json($entry, 201);
    }

    /** Lines may only use the company's own accounts or the shared (no-company) ones. */
    private function assertCompanyAccounts(array $lines, int $companyId): void
    {
        $ids = collect($lines)->pluck('coa_id')->filter()->unique();
        if ($ids->isEmpty() || ! Schema::hasColumn('acc_chart_of_accounts', 'company_id')) {
            return;
        }
        $foreign = DB::table('acc_chart_of_accounts')->whereIn('id', $ids)->whereNotNull('company_id')->where('company_id', '!=', $companyId)->exists();
        abort_if($foreign, 422, 'An account belongs to another company.');
    }

    public function post(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'journal_entries', Account::class);
        $entry = $this->entries($request)->findOrFail($id);
        if ($entry->status === 'posted') {
            return response()->json($entry);
        }

        // Ensure balanced before posting
        $totals = JournalEntryLine::where('journal_entry_id', $entry->id)
            ->select(DB::raw('SUM(debit) as debit'), DB::raw('SUM(credit) as credit'))
            ->first();
        $debit = (float) ($totals->debit ?? 0);
        $credit = (float) ($totals->credit ?? 0);
        if (round($debit - $credit, 4) !== 0.0) {
            return response()->json(['message' => __('Entry not balanced. Add lines so debits equal credits before posting.')], 422);
        }
        $entry->status = 'posted';
        $entry->posted_at = Carbon::now();
        $entry->save();

        return response()->json($entry);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'journal_entries', Account::class);

        $entry = $this->entries($request)->with('lines')->findOrFail($id);
        if ($entry->status === 'posted') {
            return response()->json(['message' => __('Posted entries cannot be edited. Create an adjusting entry instead.')], 422);
        }
        if ($entry->company_id) {
            $this->assertCompanyAccounts($request->get('lines', []), (int) $entry->company_id);
        }

        $this->validate($request, [
            'date' => 'required|date',
            'lines' => 'required|array|min:1',
            'lines.*.debit' => 'numeric',
            'lines.*.credit' => 'numeric',
        ]);

        $lines = $request->get('lines', []);
        // Allow saving unbalanced drafts; balance will be enforced on posting

        DB::transaction(function () use ($request, $entry, $lines) {
            $entry->update([
                'date' => $request->get('date'),
                'description' => $request->get('description'),
            ]);
            // Replace lines atomically
            JournalEntryLine::where('journal_entry_id', $entry->id)->delete();
            foreach ($lines as $l) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'coa_id' => $l['coa_id'] ?? null,
                    'account_id' => $l['account_id'] ?? null,
                    'debit' => (float) ($l['debit'] ?? 0),
                    'credit' => (float) ($l['credit'] ?? 0),
                    'memo' => $l['memo'] ?? null,
                ]);
            }
        });

        return response()->json($entry->fresh('lines'));
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeForUser($request->user('api'), 'journal_entries', Account::class);

        $entry = $this->entries($request)->findOrFail($id);
        if ($entry->status === 'posted') {
            return response()->json(['message' => __('Posted entries cannot be deleted. Create a reversing entry instead.')], 422);
        }
        DB::transaction(function () use ($entry) {
            JournalEntryLine::where('journal_entry_id', $entry->id)->delete();
            $entry->delete();
        });

        return response()->json(['success' => true]);
    }
}
