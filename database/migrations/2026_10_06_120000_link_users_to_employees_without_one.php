<?php

use App\Models\Setting;
use App\Services\LinkedUserService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every employee has a login (user's rule, 2026-10-06): employees without a
 * users row (none, or pointing at a user that no longer exists) get one now,
 * the same way the employee form creates it (LinkedUserService::provision,
 * type employee, role "Employee"). Active only when the company has a default
 * employee password, else disabled until System access sets one. From now on
 * the Employee model creates it on its own (Employee::booted).
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphans = DB::table('employees')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('user_id')
                ->orWhereNotExists(fn ($s) => $s->select(DB::raw(1))->from('users')->whereColumn('users.id', 'employees.user_id')))
            ->get(['id', 'company_id', 'firstname', 'lastname', 'username', 'email', 'phone']);
        foreach ($orphans as $e) {
            if (! $e->company_id) {
                continue;
            }
            $userId = LinkedUserService::provision(
                (int) $e->company_id, 'employee', 'Employee',
                trim($e->firstname.' '.$e->lastname) ?: (string) $e->username, $e->email ?: null, $e->phone ?: null
            );
            $hasDefault = (bool) Setting::defaultPasswordHash((int) $e->company_id, 'employee');
            DB::table('users')->where('id', $userId)->update(['statut' => $hasDefault ? 1 : 0]);
            DB::table('employees')->where('id', $e->id)->update(['user_id' => $userId]);
        }
    }

    public function down(): void
    {
        // The logins stay (they may already be used).
    }
};
