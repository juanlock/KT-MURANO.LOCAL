<?php

namespace App\Http\Controllers;

use App\Models\ServiceJob;
use App\Models\ServiceJobPhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class ServiceJobPhotoController extends BaseController
{
    // Multicompany: only the company's jobs (location / customer) -- see the trait.
    use \App\Http\Controllers\Concerns\ScopesServiceJobsToCompany;

    // -------------- List photos for a service job ---------------\\
    public function index(Request $request, $serviceJobId)
    {
        $this->authorizeForUser($request->user('api'), 'view', ServiceJob::class);

        $job = $this->scopedJobs()->whereNull('deleted_at')->findOrFail($serviceJobId);

        $photos = ServiceJobPhoto::where('service_job_id', $job->id)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get()
            ->map(function (ServiceJobPhoto $row) {
                return [
                    'id' => $row->id,
                    'stage' => $row->stage,
                    'path' => $row->path,
                    'url' => asset($row->path),
                    'original_name' => $row->original_name,
                    'mime_type' => $row->mime_type,
                    'size' => $row->size,
                    'caption' => $row->caption,
                    'created_at' => $row->created_at,
                ];
            });

        return response()->json(['photos' => $photos]);
    }

    // -------------- Upload one or more photos ---------------\\
    public function store(Request $request, $serviceJobId)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $validated = $request->validate([
            'stage' => 'nullable|string|in:intake,before,after,delivery',
            'caption' => 'nullable|string|max:1000',
            'photos' => 'required|array|min:1',
            'photos.*' => 'file|image|max:10240', // 10MB per file
        ]);

        $job = $this->scopedJobs()->whereNull('deleted_at')->findOrFail($serviceJobId);
        $stage = $validated['stage'] ?? 'intake';

        // Project standard: public/files/company_{id}/service_photos/{job} (CompanyFiles).
        $relDir = \App\Support\CompanyFiles::dir($this->jobCompanyId($job, $request), 'service_photos/'.$job->id);
        $uploadPath = public_path($relDir);

        $created = [];
        foreach ($request->file('photos') as $file) {
            $originalName = $file->getClientOriginalName();
            $size = $file->getSize();
            $mimeType = $file->getMimeType();

            $filename = time().'_'.Str::random(10).'_'.$originalName;
            $file->move($uploadPath, $filename);

            $relativePath = $relDir.'/'.$filename;

            $photo = ServiceJobPhoto::create([
                'service_job_id' => $job->id,
                'user_id' => Auth::id(),
                'stage' => $stage,
                'path' => $relativePath,
                'original_name' => $originalName,
                'mime_type' => $mimeType,
                'size' => $size,
                'caption' => $validated['caption'] ?? null,
            ]);

            $created[] = [
                'id' => $photo->id,
                'stage' => $photo->stage,
                'path' => $photo->path,
                'url' => asset($photo->path),
                'original_name' => $photo->original_name,
                'mime_type' => $photo->mime_type,
                'size' => $photo->size,
                'caption' => $photo->caption,
            ];
        }

        return response()->json(['success' => true, 'photos' => $created], 201);
    }

    /** A job has no company_id: its location's company, else its customer's, else the acting company. */
    private function jobCompanyId(ServiceJob $job, Request $request): int
    {
        $cid = $job->warehouse_id ? \Illuminate\Support\Facades\DB::table('warehouses')->where('id', $job->warehouse_id)->value('company_id') : null;
        $cid = $cid ?: ($job->client_id ? \Illuminate\Support\Facades\DB::table('clients')->where('id', $job->client_id)->value('company_id') : null);

        return (int) ($cid ?: \App\Support\CompanyFiles::actingCompanyId($request));
    }

    // -------------- Delete a photo ---------------\\
    public function destroy(Request $request, $serviceJobId, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', ServiceJob::class);

        $job = $this->scopedJobs()->whereNull('deleted_at')->findOrFail($serviceJobId);

        $photo = ServiceJobPhoto::whereNull('deleted_at')
            ->where('service_job_id', $job->id)
            ->findOrFail($id);

        if ($photo->path) {
            $absolute = public_path($photo->path);
            if (file_exists($absolute)) {
                @unlink($absolute);
            }
        }

        $photo->update(['deleted_at' => now()]);

        return response()->json(['success' => true]);
    }
}
