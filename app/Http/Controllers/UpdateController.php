<?php

namespace App\Http\Controllers;

use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\sms_gateway;
use Auth;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class UpdateController extends Controller
{
    public function get_version_info(Request $request)
    {
        $currentVersion = trim(File::get(base_path('version.txt')));

        // Fetch latest version info from update server
        $latestVersion = '';
        $latestInfo = null;
        $changelog = [];

        try {
            $ctx = stream_context_create([
                'http' => ['timeout' => 10],
                'https' => ['timeout' => 10],
            ]);
            $json = @file_get_contents('https://update-stocky.ui-lib.com/stocky_version.json', false, $ctx);
            if ($json !== false) {
                $latestInfo = json_decode($json, true);
                $latestVersion = $latestInfo['version'] ?? '';

                // Build changelog from remote data if available
                if (!empty($latestInfo['changelog'])) {
                    $changelog = $latestInfo['changelog'];
                } elseif (!empty($latestInfo['release_notes'])) {
                    $changelog = [
                        [
                            'version' => $latestVersion,
                            'date' => $latestInfo['date'] ?? now()->toDateString(),
                            'items' => array_map(function ($note) {
                                if (is_string($note)) {
                                    return ['type' => 'misc', 'text' => $note];
                                }
                                return $note;
                            }, (array) $latestInfo['release_notes']),
                        ],
                    ];
                }
            }
        } catch (\Throwable $e) {
            // Silently fail - we'll just show current version
        }

        // Load update history
        $updateHistory = [];
        $historyFile = storage_path('app/update_history.json');
        if (File::exists($historyFile)) {
            $updateHistory = json_decode(File::get($historyFile), true) ?: [];
            $updateHistory = array_reverse($updateHistory);
        }

        return response()->json([
            'current_version' => $currentVersion,
            'latest_version' => $latestVersion,
            'latest_info' => $latestInfo,
            'changelog' => $changelog,
            'update_history' => array_slice($updateHistory, 0, 10),
        ]);
    }

    public function viewStep1(Request $request)
    {
        $role = Auth::user()->roles()->first();
        $permission = Role::findOrFail($role->id)->inRole('setting_system');
        if ($permission) {
            $version = null;
            try {
                if (File::exists(base_path('version.txt'))) {
                    $version = trim(File::get(base_path('version.txt')));
                }
            } catch (\Throwable $e) {
                // Version chip is optional — never block the update page over it.
            }

            return view('update.viewStep1', ['version' => $version]);
        }
    }

    // Repair tables whose `id` primary key lost its AUTO_INCREMENT attribute
    // (usually caused by importing a SQL dump that dropped the clause).
    public function fix_auto_increment(Request $request)
    {
        $role = Auth::user()->roles()->first();
        $permission = Role::findOrFail($role->id)->inRole('setting_system');

        if (! $permission) {
            return response()->json(['success' => false, 'message' => 'Not allowed.'], 403);
        }

        ini_set('max_execution_time', 2000);

        $database = DB::getDatabaseName();

        // Tables with a single-column integer primary key named `id` that is NOT auto_increment
        $broken = DB::select("
            SELECT c.TABLE_NAME AS table_name, c.COLUMN_TYPE AS column_type
            FROM information_schema.COLUMNS c
            JOIN information_schema.TABLES t
              ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
            WHERE c.TABLE_SCHEMA = ?
              AND t.TABLE_TYPE = 'BASE TABLE'
              AND c.COLUMN_NAME = 'id'
              AND c.COLUMN_KEY = 'PRI'
              AND c.EXTRA NOT LIKE '%auto_increment%'
              AND c.DATA_TYPE IN ('tinyint', 'smallint', 'mediumint', 'int', 'bigint')
              AND (SELECT COUNT(*) FROM information_schema.COLUMNS c2
                   WHERE c2.TABLE_SCHEMA = c.TABLE_SCHEMA
                     AND c2.TABLE_NAME = c.TABLE_NAME
                     AND c2.COLUMN_KEY = 'PRI') = 1
        ", [$database]);

        $fixed = [];
        $errors = [];

        foreach ($broken as $col) {
            $table = $col->table_name;

            try {
                // A row with id = 0 is a leftover from inserts made while auto_increment
                // was missing. Renumber it first so the ALTER cannot renumber it implicitly.
                if (DB::table($table)->where('id', 0)->exists()) {
                    $max = (int) DB::table($table)->max('id');
                    DB::table($table)->where('id', 0)->update(['id' => $max + 1]);
                }

                DB::statement("ALTER TABLE `{$table}` MODIFY `id` {$col->column_type} NOT NULL AUTO_INCREMENT");
                $fixed[] = $table;

            } catch (\Throwable $e) {
                $errors[$table] = $e->getMessage();
            }
        }

        return response()->json([
            'success' => count($errors) === 0,
            'checked_tables_needing_fix' => count($broken),
            'fixed' => $fixed,
            'errors' => $errors,
        ]);
    }

    public function lastStep(Request $request)
    {
        ini_set('max_execution_time', 2000);
        ini_set('memory_limit', '512M');

        $role = Auth::user()->roles()->first();
        $permission = Role::findOrFail($role->id)->inRole('setting_system');

        if ($permission) {

            try {
                Artisan::call('config:cache');
                Artisan::call('config:clear');

                $tasks = new \App\Services\Updater\PostUpdateTasks;

                // Pre-migration data fixes (must see the OLD schema)
                $tasks->preMigration();

                Artisan::call('migrate', ['--force' => true]);

                // Permissions, gateways, templates, seeders, data clean-ups
                $tasks->run();

                // Clear caches so translations are picked up immediately
                Artisan::call('cache:clear');
                Artisan::call('config:clear');
                Artisan::call('view:clear');
                Artisan::call('route:clear');

            } catch (\Exception $e) {

                return $e->getMessage();
            }

            return view('update.finishedUpdate');
        }
    }
}
