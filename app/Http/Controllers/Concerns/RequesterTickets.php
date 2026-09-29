<?php

namespace App\Http\Controllers\Concerns;

use App\Models\HelpdeskSetting;
use App\Services\Helpdesk\Helpdesk;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Shared validation for the customer-facing helpdesk channels (public
 * form, client portal, online-store account). The helpdesk logic itself
 * lives in App\Services\Helpdesk\Helpdesk -- this only keeps the three
 * channels' input rules identical to MyTicketsController's.
 */
trait RequesterTickets
{
    /** The company's ticket categories (never creates the settings row). */
    protected function ticketCategories(int $companyId): array
    {
        $s = HelpdeskSetting::where('company_id', $companyId)->first();
        $cats = $s ? ($s->categories ?? []) : ['General', 'Billing', 'Technical', 'Orders'];

        return array_values(array_filter(array_map('strval', is_array($cats) ? $cats : []), fn ($c) => trim($c) !== ''));
    }

    protected function fileRules(): array
    {
        return [
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|max:'.Helpdesk::ATTACH_MAX_KB.'|mimes:'.Helpdesk::ATTACH_MIMES,
        ];
    }

    /** subject + body + category (must be one of the company's) + files. */
    protected function openRules(int $companyId): array
    {
        $cats = $this->ticketCategories($companyId);

        return [
            'subject' => 'required|string|max:250',
            'body' => 'required|string|max:60000',
            'category' => array_merge(['nullable', 'string', 'max:80'], $cats ? [Rule::in($cats)] : []),
        ] + $this->fileRules();
    }

    protected function replyRules(): array
    {
        return ['body' => 'required|string|max:60000'] + $this->fileRules();
    }

    protected function uploads(Request $request, int $companyId): array
    {
        return Helpdesk::storeUploads($companyId, (array) $request->file('files', []));
    }
}
