<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Nwidart\Modules\Facades\Module;

class ModuleSettingsController extends Controller
{
    public function get_modules_info()
    {
        $modules = Module::all();
        $result = [];

        foreach ($modules as $name => $module) {
            $moduleJson = json_decode(File::get($module->getPath() . '/module.json'), true);
            $result[] = [
                'module_name'     => $name,
                'current_version' => $moduleJson['version'] ?? '1.0.0',
                'status'          => $module->isEnabled(),
            ];
        }

        return response()->json($result);
    }

    /** Installing / switching code modules affects the whole install: SuperAdmin only. */
    private function abortUnlessSuperAdmin(Request $request): void
    {
        abort_unless(optional($request->user('api'))->type === 'SuperAdmin', 403, 'Only a SuperAdmin can manage code modules.');
    }

    public function update_status_module(Request $request)
    {
        $this->abortUnlessSuperAdmin($request);
        $request->validate([
            'name'   => 'required|string',
            'status' => 'required',
        ]);

        $module = Module::find($request->name);

        if (!$module) {
            return response()->json(['message' => __('Module not found')], 404);
        }

        if ($request->status) {
            $module->enable();
        } else {
            $module->disable();
        }

        return response()->json(['message' => __('Module status updated')]);
    }

    public function upload_module(Request $request)
    {
        $this->abortUnlessSuperAdmin($request);
        $request->validate([
            'module_zip' => 'required|file|mimes:zip',
        ]);

        $file = $request->file('module_zip');
        $modulesPath = base_path('Modules');

        if (!File::isDirectory($modulesPath)) {
            File::makeDirectory($modulesPath, 0755, true);
        }

        $tmpPath = storage_path('app/tmp_module_' . time());
        File::makeDirectory($tmpPath, 0755, true);

        $zip = new \ZipArchive;
        if ($zip->open($file->getRealPath()) === true) {
            $zip->extractTo($tmpPath);
            $zip->close();
        } else {
            File::deleteDirectory($tmpPath);
            return response()->json(['message' => __('Failed to extract zip')], 422);
        }

        // Find the module.json to identify the module
        $extracted = File::directories($tmpPath);
        $sourceDir = count($extracted) === 1 ? $extracted[0] : $tmpPath;

        if (!File::exists($sourceDir . '/module.json')) {
            // Maybe files are directly in tmp without subfolder
            if (File::exists($tmpPath . '/module.json')) {
                $sourceDir = $tmpPath;
            } else {
                File::deleteDirectory($tmpPath);
                return response()->json(['message' => __('Invalid module: module.json not found')], 422);
            }
        }

        $moduleJson = json_decode(File::get($sourceDir . '/module.json'), true);
        $moduleName = $moduleJson['name'] ?? null;

        if (!$moduleName) {
            File::deleteDirectory($tmpPath);
            return response()->json(['message' => __('Invalid module.json')], 422);
        }

        $targetPath = $modulesPath . '/' . $moduleName;

        // Remove old version if exists
        if (File::isDirectory($targetPath)) {
            File::deleteDirectory($targetPath);
        }

        File::moveDirectory($sourceDir, $targetPath);
        File::deleteDirectory($tmpPath);

        // Enable the module
        $module = Module::find($moduleName);
        if ($module) {
            $module->enable();
        }

        return response()->json(['message' => __('Module uploaded successfully')]);
    }
}
