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
}
