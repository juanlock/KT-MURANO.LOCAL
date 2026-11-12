<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Change history (user's request, 2026-11-12; seen only by the SuperAdmin, "Goku"): every model created, changed,
 * deleted or restored during a web / API request goes to audit_logs -- who, company, when, IP, page, and the values
 * before and after (only the fields that changed).
 *
 * Covers what the app writes through its models (Eloquent events, AppServiceProvider). Not covered: writes with the
 * query builder (DB::table(...)), migrations, and the console (cron, demo routines, imports) -- those are not a
 * person's change and would bury the history. Secrets are never stored (passwords, tokens, keys, PINs: "••••").
 * Never breaks the change it records.
 */
class AuditTrail
{
    /** Tables never recorded: logs, sessions, tokens, positions, counters, queues. */
    private const SKIP = ['audit_logs', 'kt_logs', 'login_events', 'login_devices', 'login_links', 'user_login_sessions', 'sessions',
        'jobs', 'failed_jobs', 'job_batches', 'notifications', 'cache', 'cache_locks', 'password_histories', 'password_resets',
        'password_reset_tokens', 'personal_access_tokens', 'trusted_devices', 'transport_vehicle_positions', 'form_stats_daily',
        'booking_public_requests', 'migrations', 'menu_positions'];

    /** Also skipped: logs, sessions, tokens, counters, and the mailboxes (Email Office keeps every mail sent / received). */
    private const SKIP_PATTERN = '/(^oauth_|_logs?$|_sessions$|_tokens$|_stats(_|$)|_cache$|^telescope_|^office_email)/';

    /** Field names whose values are never written down. */
    private const SECRET = '/password|passwd|token|secret|api_?key|private_?key|client_?secret|(^|_)pin($|_)|(^|_)otp($|_)|recovery_codes|two_factor|remember_token|encrypted/i';

    private const IGNORE_FIELDS = ['updated_at', 'created_at', 'last_seen_at', 'last_activity_at', 'last_login_at', 'last_used_at'];

    private static ?bool $ready = null;

    /** Write statements of this request not (yet) covered by a model event: flushed when the request ends. */
    private static array $pending = [];

    private static bool $flushRegistered = false;

    private static bool $writing = false;

    private static function skipTable(string $table): bool
    {
        return in_array($table, self::SKIP, true) || (bool) preg_match(self::SKIP_PATTERN, $table);
    }

    /**
     * Every INSERT / UPDATE / DELETE of a request (DB::listen): what model events can't see -- mass updates
     * (Model::where(...)->update()) and the query builder (DB::table(...)). Recorded with the new values only (the
     * old ones are gone by then), unless a model event of the same row records it with before / after.
     */
    public static function statement(\Illuminate\Database\Events\QueryExecuted $q): void
    {
        try {
            if (self::$writing || app()->runningInConsole() || ! preg_match('/^\s*(insert\s+into|update|delete\s+from)\s+`?([a-z0-9_]+)`?/i', $q->sql, $m)) {
                return;
            }
            $table = strtolower($m[2]);
            if (self::skipTable($table) || ! self::ready()) {
                return;
            }
            $op = strtolower(substr(trim($m[1]), 0, 6));
            self::$pending[] = ['op' => $op === 'insert' ? 'created' : ($op === 'update' ? 'updated' : 'deleted'), 'table' => $table,
                'sql' => $q->sql, 'bindings' => $q->bindings, 'user' => self::user(), 'at' => now()];
            if (! self::$flushRegistered) {
                self::$flushRegistered = true;
                app()->terminating(fn () => self::flush());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** The statements left after the model events of the same rows were recorded. */
    public static function flush(): void
    {
        $pending = self::$pending;
        self::$pending = [];
        foreach ($pending as $p) {
            try {
                [$changes, $recordId] = self::parse($p['op'], $p['sql'], $p['bindings']);
                if (! $changes && $p['op'] !== 'deleted') {
                    continue;
                }
                // A soft delete (only deleted_at set) is a deletion for whoever reads the history.
                $event = $p['op'] === 'updated' && array_keys($changes) === ['deleted_at'] && $changes['deleted_at'][1] !== null ? 'deleted' : $p['op'];
                self::write($event, $p['table'], 'SQL', $recordId, $changes, $p['user'], null, $p['at']);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /** [{column: [null, new value]}, record id] from an INSERT / UPDATE / DELETE with its bindings. */
    private static function parse(string $op, string $sql, array $bindings): array
    {
        $changes = [];
        $recordId = null;
        if ($op === 'updated' && preg_match('/\bset\s+(.+?)\s+where\s+(.+)$/is', $sql, $m)) {
            $i = 0;
            foreach (preg_split('/,\s*(?=`)/', $m[1]) as $assign) {
                // `col` = ?  or  `table`.`col` = ?  or  `col` = `col` - ?  (every ? takes the next binding, in order)
                if (! preg_match('/^(?:`?[a-z0-9_]+`?\.)?`?([a-z0-9_]+)`?\s*=\s*(.+)$/is', trim($assign), $a)) {
                    $i += substr_count($assign, '?');
                    continue;
                }
                $expr = trim($a[2]);
                $val = $expr === '?' ? ($bindings[$i] ?? null) : '= '.$expr;
                $i += substr_count($expr, '?');
                if (! in_array($a[1], self::IGNORE_FIELDS, true)) {
                    $changes[$a[1]] = [null, self::value($a[1], $val)];
                }
            }
            if (preg_match('/^`?(?:[a-z0-9_]+`?\.`?)?id`?\s*=\s*\?/i', trim($m[2]))) {
                $recordId = $bindings[$i] ?? null;
            }
        } elseif ($op === 'created' && preg_match('/\(([^)]+)\)\s*values\s*\(/is', $sql, $m)) {
            $cols = array_map(fn ($c) => trim($c, " `"), explode(',', $m[1]));
            foreach ($cols as $k => $c) {
                if (! in_array($c, self::IGNORE_FIELDS, true) && ($bindings[$k] ?? null) !== null && ($bindings[$k] ?? '') !== '') {
                    $changes[$c] = [null, self::value($c, $bindings[$k])];
                }
            }
            if (count($bindings) > count($cols)) {
                $changes['_rows'] = [null, (int) floor(count($bindings) / max(1, count($cols)))];
            }
        } elseif ($op === 'deleted' && preg_match('/\bwhere\s+`?(?:[a-z0-9_]+`?\.`?)?id`?\s*=\s*\?/i', $sql)) {
            $recordId = $bindings[0] ?? null;
        }

        return [$changes, $recordId !== null ? (string) $recordId : null];
    }

    /** A model event covered these statements: they are not written twice. */
    private static function covered(string $table, ?string $id, string $event): void
    {
        $sqlOp = $event === 'restored' ? 'updated' : $event;
        foreach (self::$pending as $k => $p) {
            if ($p['table'] !== $table || $p['op'] !== $sqlOp) {
                continue;
            }
            [, $rid] = self::parse($p['op'], $p['sql'], $p['bindings']);
            // An INSERT has no id in its SQL: the latest pending insert of that table is this model's.
            if ($rid === $id || ($p['op'] === 'created' && $rid === null)) {
                unset(self::$pending[$k]);

                return;
            }
        }
    }

    public static function record(string $event, $model): void
    {
        try {
            if (! $model instanceof Model || app()->runningInConsole() || ! self::ready()) {
                return;
            }
            $table = $model->getTable();
            if (self::skipTable($table)) {
                return;
            }
            self::covered($table, $model->getKey() !== null ? (string) $model->getKey() : null, $event);

            $changes = [];
            if ($event === 'updated') {
                foreach ($model->getChanges() as $field => $after) {
                    if (in_array($field, self::IGNORE_FIELDS, true)) {
                        continue;
                    }
                    $changes[$field] = [self::value($field, $model->getOriginal($field)), self::value($field, $after)];
                }
                if (! $changes) {
                    return;   // only timestamps moved
                }
            } else {
                foreach ($model->getAttributes() as $field => $v) {
                    if (in_array($field, self::IGNORE_FIELDS, true) || $v === null || $v === '') {
                        continue;
                    }
                    $value = self::value($field, $v);
                    $changes[$field] = $event === 'created' ? [null, $value] : [$value, null];
                }
            }

            self::write($event, $table, get_class($model), $model->getKey() !== null ? (string) $model->getKey() : null,
                $changes, self::user(), $model->getAttribute('company_id'), now());
        } catch (\Throwable $e) {
            report($e);   // the history never blocks the change itself
        }
    }

    private static function write(string $event, string $table, string $model, ?string $recordId, array $changes, ?object $user, $companyId, $at): void
    {
        $request = request();
        $companyId = $companyId ?? ($user->company_id ?? null);
        self::$writing = true;
        try {
            DB::table('audit_logs')->insert([
                'company_id' => is_numeric($companyId) ? (int) $companyId : null,
                'user_id' => $user->id ?? null,
                'user_name' => $user ? mb_substr(trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: (string) ($user->username ?? $user->email ?? ''), 0, 150) : null,
                'as_admin_id' => self::adminBehind($user),
                'event' => $event, 'table_name' => mb_substr($table, 0, 64), 'model' => mb_substr($model, 0, 120),
                'record_id' => $recordId !== null ? mb_substr($recordId, 0, 40) : null,
                'changes' => json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'ip' => $request?->ip(), 'method' => $request?->method(),
                'url' => $request ? mb_substr('/'.ltrim($request->path(), '/'), 0, 255) : null,
                'created_at' => $at,
            ]);
        } finally {
            self::$writing = false;
        }
    }

    private static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('audit_logs');
    }

    private static function value(string $field, $v)
    {
        if (preg_match(self::SECRET, $field)) {
            return $v === null || $v === '' ? $v : '••••';
        }
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d H:i:s');
        }
        if (is_array($v) || is_object($v)) {
            $v = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        if (is_string($v) && mb_strlen($v) > 500) {
            return mb_substr($v, 0, 500).'…';
        }

        return $v;
    }

    private static function user(): ?object
    {
        foreach (['web', 'api', 'portal'] as $guard) {
            try {
                if ($u = Auth::guard($guard)->user()) {
                    return $u;
                }
            } catch (\Throwable $e) {
                // guard not usable in this request
            }
        }

        return null;
    }

    /** The company admin who opened this login from Company logins (Enter), if any. */
    private static function adminBehind(?object $user): ?int
    {
        try {
            $admin = $user ? AdminSwitch::adminFor($user, request()) : null;

            return $admin->id ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
