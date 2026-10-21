<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Ported from KT-Billing. Resolves which company an admin request acts on.
 * A SuperAdmin may act on any company via ?company_id=/company_id= (for a
 * frontend company picker to send, once one exists); any other user is
 * always locked to their own company_id, ignoring whatever the client sends.
 */
trait ScopesToCompany
{
    protected function resolveActingCompanyId(Request $request): int
    {
        $user = $request->user('api');

        if ($user && $user->type === 'SuperAdmin') {
            $requested = $request->input('company_id');
            if ($requested) {
                return (int) $requested;
            }
        }

        return (int) ($user->company_id ?? 1);
    }

    /**
     * The company a LIST / report reads (user's rule, 2026-10-21): null = every
     * company -- "superadmin/AdminSystem" (SuperAdmin with AdminSystem 999, or
     * nothing, picked in the switcher) has no restriction; a SuperAdmin with a
     * company picked reads that company; anyone else their own company.
     * Writes (a new record's company, settings) keep using resolveActingCompanyId().
     */
    protected function listCompanyId(Request $request): ?int
    {
        $user = $request->user('api');
        if ($user && $user->type === 'SuperAdmin') {
            $requested = (int) $request->input('company_id');

            return (! $requested || $requested === \App\Models\Setting::TEMPLATE_COMPANY_ID) ? null : $requested;
        }

        return (int) ($user->company_id ?? 1);
    }

    /** Applies listCompanyId() to a query on $column (nothing for "every company"). */
    protected function scopeListToCompany($query, Request $request, string $column = 'company_id')
    {
        $cid = $this->listCompanyId($request);

        return $cid === null ? $query : $query->where($column, $cid);
    }
}
