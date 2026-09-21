<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Updater\BackupManager;
use App\Services\Updater\PackageValidator;
use App\Services\Updater\UpdateManager;
use App\Services\Updater\UpdaterPaths;
use App\Services\Updater\UpdateState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

/**
 * Upload-based System Update.
 *
 * All endpoints require an authenticated admin with the update_settings
 * permission (SettingPolicy::update_settings) and are whitelisted in the
 * maintenance-mode middleware so the update UI keeps working while the
 * application is down for updating.
 */
class SystemUpdateController extends Controller
{
    /** Max accepted size for one upload chunk (must stay under post_max_size). */
    private const MAX_CHUNK = 8 * 1024 * 1024;

    private function authorize_update(Request $request): void
    {
        $this->authorizeForUser($request->user('api'), 'update_settings', Setting::class);
    }

    private function currentUser(Request $request): ?array
    {
        $user = $request->user('api');

        return $user ? ['id' => $user->id, 'name' => $user->username ?? $user->firstname ?? ('user #'.$user->id)] : null;
    }

    // ---------------------------------------------------------------- status

    public function status(Request $request)
    {
        $this->authorize_update($request);

        return response()->json((new UpdateManager)->status());
    }

    /** Unauthenticated liveness probe used by the post-update health check. */
    public function ping()
    {
        return response('pong', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Standalone recovery console (web route, session auth). Kept outside
     * the SPA so it still works when SPA assets are broken mid-update.
     */
    public function recoveryConsole(Request $request)
    {
        $user = \Auth::user();
        $role = $user ? $user->roles()->first() : null;
        $allowed = $role ? \App\Models\Role::findOrFail($role->id)->inRole('update_settings') : false;
        if (! $allowed) {
            abort(403);
        }

        return view('update.system-recovery');
    }

    // ---------------------------------------------------------------- upload

    public function uploadChunk(Request $request)
    {
        $this->authorize_update($request);

        if ((new UpdateState)->isActive()) {
            return response()->json(['message' => 'An update is currently in progress. Please wait until it has finished.'], 409);
        }

        $request->validate([
            'chunk' => 'required|file',
            'index' => 'required|integer|min:0',
            'total' => 'required|integer|min:1|max:20000',
            'upload_id' => 'required|string|max:64',
            'filename' => 'nullable|string|max:255',
            'sha256' => 'nullable|string|size:64',
        ]);

        $index = (int) $request->input('index');
        $total = (int) $request->input('total');
        $uploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->input('upload_id'));
        $chunk = $request->file('chunk');
        if ($chunk->getSize() > self::MAX_CHUNK) {
            return response()->json(['message' => 'Upload chunk too large.'], 413);
        }

        UpdaterPaths::ensureDirectories();
        $part = UpdaterPaths::packagePart();
        $metaFile = $part.'.meta.json';

        if ($index === 0) {
            @File::delete($part);
            @File::delete($metaFile);
            @File::delete(UpdaterPaths::packageZip());
            @File::delete(UpdaterPaths::packageMeta());
            File::put($metaFile, json_encode(['upload_id' => $uploadId, 'next' => 0, 'total' => $total]));
        }

        $meta = json_decode((string) @File::get($metaFile), true);
        if (! is_array($meta) || ($meta['upload_id'] ?? null) !== $uploadId) {
            return response()->json(['message' => 'Upload session expired or superseded — please restart the upload.'], 409);
        }
        if ((int) $meta['next'] !== $index) {
            return response()->json(['message' => 'Out-of-order upload chunk — please restart the upload.', 'expected' => $meta['next']], 409);
        }

        // Append the chunk
        $in = fopen($chunk->getRealPath(), 'rb');
        $out = fopen($part, $index === 0 ? 'wb' : 'ab');
        if (! $in || ! $out) {
            return response()->json(['message' => 'Could not write the uploaded chunk to disk.'], 500);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        $meta['next'] = $index + 1;
        File::put($metaFile, json_encode($meta));

        if ($index + 1 < $total) {
            return response()->json(['received' => $index + 1, 'total' => $total]);
        }

        // ------- last chunk: finalize --------------------------------------
        $sha256 = strtolower((string) $request->input('sha256', ''));
        if ($sha256 !== '') {
            $actual = hash_file('sha256', $part);
            if (! hash_equals($sha256, $actual)) {
                @File::delete($part);
                @File::delete($metaFile);

                return response()->json(['message' => 'Upload corrupted in transit (checksum mismatch). Please upload again.'], 422);
            }
        }

        $zip = new \ZipArchive;
        if ($zip->open($part) !== true) {
            @File::delete($part);
            @File::delete($metaFile);

            return response()->json(['message' => 'The uploaded file is not a valid ZIP archive.'], 422);
        }
        $zip->close();

        @File::delete(UpdaterPaths::packageZip());
        if (! @rename($part, UpdaterPaths::packageZip())) {
            return response()->json(['message' => 'Could not finalize the uploaded package on disk.'], 500);
        }
        @File::delete($metaFile);

        File::put(UpdaterPaths::packageMeta(), json_encode([
            'filename' => substr((string) $request->input('filename', 'package.zip'), 0, 255),
            'size' => File::size(UpdaterPaths::packageZip()),
            'sha256' => $sha256 ?: null,
            'uploaded_at' => date('Y-m-d H:i:s'),
            'validated' => false,
            'report' => null,
        ], JSON_PRETTY_PRINT));

        return response()->json(['received' => $total, 'total' => $total, 'complete' => true]);
    }

    // -------------------------------------------------------------- validate

    public function validatePackage(Request $request)
    {
        $this->authorize_update($request);

        if (! File::exists(UpdaterPaths::packageZip())) {
            return response()->json(['message' => 'No uploaded package found — upload the update ZIP first.'], 404);
        }

        $report = (new PackageValidator)->validate(UpdaterPaths::packageZip());

        $meta = json_decode((string) @File::get(UpdaterPaths::packageMeta()), true) ?: [];
        $meta['validated'] = true;
        $meta['validated_at'] = date('Y-m-d H:i:s');
        $meta['ok'] = $report['ok'];
        $meta['report'] = $report;
        $meta['version'] = $report['package']['version'] ?? null;
        File::put(UpdaterPaths::packageMeta(), json_encode($meta, JSON_PRETTY_PRINT));

        return response()->json($report);
    }

    // ----------------------------------------------------------------- runs

    public function start(Request $request)
    {
        $this->authorize_update($request);

        $meta = json_decode((string) @File::get(UpdaterPaths::packageMeta()), true) ?: [];
        if (empty($meta['validated']) || empty($meta['ok'])) {
            return response()->json(['message' => 'The package has not passed validation. Validate it before updating.'], 422);
        }

        try {
            $state = (new UpdateManager)->startUpdate($meta, $this->currentUser($request));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json((new UpdateManager)->status());
    }

    public function step(Request $request)
    {
        $this->authorize_update($request);

        try {
            (new UpdateManager)->step();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json((new UpdateManager)->status());
    }

    public function resume(Request $request)
    {
        $this->authorize_update($request);

        try {
            (new UpdateManager)->resume();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json((new UpdateManager)->status());
    }

    public function rollback(Request $request)
    {
        $this->authorize_update($request);

        try {
            (new UpdateManager)->requestRollback();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json((new UpdateManager)->status());
    }

    public function discard(Request $request)
    {
        $this->authorize_update($request);

        try {
            (new UpdateManager)->discard();
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json((new UpdateManager)->status());
    }

    /** Remove an uploaded (not yet applied) package. */
    public function deletePackage(Request $request)
    {
        $this->authorize_update($request);

        if ((new UpdateState)->isActive()) {
            return response()->json(['message' => 'An update is currently in progress.'], 409);
        }
        @File::delete(UpdaterPaths::packageZip());
        @File::delete(UpdaterPaths::packagePart());
        @File::delete(UpdaterPaths::packageMeta());

        return response()->json((new UpdateManager)->status());
    }

    // --------------------------------------------------------------- backups

    public function restoreBackup(Request $request)
    {
        $this->authorize_update($request);
        $request->validate(['id' => 'required|string|max:100']);

        try {
            (new UpdateManager)->startRestore((string) $request->input('id'), $this->currentUser($request));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json((new UpdateManager)->status());
    }

    public function deleteBackup(Request $request, string $id)
    {
        $this->authorize_update($request);

        if ((new UpdateState)->isActive()) {
            return response()->json(['message' => 'An update is currently in progress.'], 409);
        }
        try {
            (new BackupManager)->deleteBackup($id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json((new UpdateManager)->status());
    }

    public function saveRetention(Request $request)
    {
        $this->authorize_update($request);
        $request->validate(['retention' => 'required|integer|min:1|max:20']);

        $stateService = new UpdateState;
        $config = $stateService->config();
        $config['retention'] = (int) $request->input('retention');
        $stateService->saveConfig($config);
        (new BackupManager)->applyRetention($config['retention']);

        return response()->json((new UpdateManager)->status());
    }
}
