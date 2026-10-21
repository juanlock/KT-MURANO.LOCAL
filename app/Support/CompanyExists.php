<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * "exists:table,id" limited to the acting company (2026-10-20). Validation
 * queries the table directly, past the models' company scope, so a plain
 * exists rule would accept another company's doctor / patient / bed id.
 *
 *   'doctor_id' => ['nullable', CompanyExists::rule('doctors')],
 */
class CompanyExists
{
    public static function rule(string $table, string $column = 'id'): Exists
    {
        $rule = Rule::exists($table, $column)->whereNull('deleted_at');
        $cid = self::actingCompanyId($table);

        return $cid !== null ? $rule->where('company_id', $cid) : $rule;
    }

    /** Same acting company as App\Models\Concerns\BelongsToActingCompany. */
    private static function actingCompanyId(string $table): ?int
    {
        if (! Schema::hasColumn($table, 'company_id')) {
            return null;
        }
        $user = request()->user('api');
        if (! $user) {
            return null;
        }
        if ($user->type === 'SuperAdmin') {
            $cid = (int) request()->input('company_id');

            return $cid && $cid !== 999 ? $cid : null;
        }

        return (int) ($user->company_id ?? 1);
    }
}
