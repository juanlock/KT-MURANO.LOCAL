<?php

namespace App\Http\Controllers\Api\Intranet;

use App\Models\IntranetFile;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Intranet Management -> Files (intranet_manage, acting company only).
 * Files are stored PRIVATELY under storage/app/intranet/company_{id}/files
 * with a random name; employees download them through
 * GET intranet/files/{id}/download (IntranetViewApiController).
 *
 *  GET    intranet-admin/files          ?search=&folder=
 *  POST   intranet-admin/files          multipart {files[], folder?, title? (single file), description?}
 *  PUT    intranet-admin/files/{id}     {title, description, folder}
 *  DELETE intranet-admin/files/{id}
 */
class IntranetFilesApiController extends IntranetBaseController
{
    public const EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,odt,ods,odp,rtf,txt,csv,jpg,jpeg,png,gif,webp,zip';

    public const MAX_KB = 20480; // 20 MB

    public static function listQuery(Request $request, int $companyId)
    {
        $q = IntranetFile::where('company_id', $companyId)->with('uploader');
        if ($s = trim((string) $request->input('search'))) {
            $q->where(fn ($w) => $w->where('title', 'like', '%'.$s.'%')->orWhere('original_name', 'like', '%'.$s.'%')->orWhere('description', 'like', '%'.$s.'%'));
        }
        if ($request->filled('folder')) {
            $request->input('folder') === '__none__' ? $q->whereNull('folder') : $q->where('folder', $request->input('folder'));
        }

        return $q->orderByDesc('created_at')->orderByDesc('id');
    }

    public static function folders(int $companyId): array
    {
        return IntranetFile::where('company_id', $companyId)->whereNotNull('folder')->distinct()->orderBy('folder')->pluck('folder')->all();
    }

    public function index(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $request->validate(['search' => 'nullable|string|max:100', 'folder' => 'nullable|string|max:120']);

        return response()->json([
            'data' => self::listQuery($request, $companyId)->get(),
            'folders' => self::folders($companyId),
            'max_kb' => self::MAX_KB,
            'extensions' => explode(',', self::EXTENSIONS),
        ]);
    }

    public function store(Request $request)
    {
        $companyId = $this->manageCompany($request);
        $data = $request->validate([
            'files' => 'required|array|min:1|max:20',
            'files.*' => 'file|max:'.self::MAX_KB.'|extensions:'.self::EXTENSIONS.'|mimes:'.self::EXTENSIONS,
            'folder' => 'nullable|string|max:120',
            'title' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:2000',
        ]);

        $relDir = 'intranet/company_'.$companyId.'/files';
        $absDir = storage_path('app/'.$relDir);
        if (! is_dir($absDir)) {
            mkdir($absDir, 0755, true);
        }
        $single = count($request->file('files')) === 1;
        $folder = isset($data['folder']) ? (trim($data['folder']) ?: null) : null;
        $created = [];
        foreach ($request->file('files') as $file) {
            $original = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 250, '');
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
            $name = Str::lower(Str::random(40)).'.'.$ext;
            $size = (int) $file->getSize();
            $mime = $file->getMimeType();
            $file->move($absDir, $name);

            $base = pathinfo($original, PATHINFO_FILENAME);
            $created[] = IntranetFile::create([
                'company_id' => $companyId,
                'user_id' => $request->user('api')->id,
                'title' => ($single && ! empty($data['title'])) ? $data['title'] : Str::limit(trim(preg_replace('/[-_]+/', ' ', $base)) ?: $original, 190, ''),
                'description' => $single ? ($data['description'] ?? null) : null,
                'folder' => $folder,
                'original_name' => $original,
                'path' => $relDir.'/'.$name,
                'mime' => Str::limit((string) $mime, 120, ''),
                'extension' => $ext,
                'size' => $size,
            ]);
        }

        return response()->json(['data' => $created], 201);
    }

    public function update(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $f = $this->own(IntranetFile::class, $companyId, $id);
        $data = $request->validate([
            'title' => 'required|string|max:190',
            'description' => 'nullable|string|max:2000',
            'folder' => 'nullable|string|max:120',
        ]);
        $data['folder'] = isset($data['folder']) ? (trim($data['folder']) ?: null) : null;
        $f->fill($data)->save();

        return response()->json($f->load('uploader'));
    }

    public function destroy(Request $request, $id)
    {
        $companyId = $this->manageCompany($request);
        $f = $this->own(IntranetFile::class, $companyId, $id);
        if (str_starts_with($f->path, 'intranet/company_'.$companyId.'/files/') && ! str_contains($f->path, '..') && is_file($f->absolutePath())) {
            @unlink($f->absolutePath());
        }
        $f->delete();

        return response()->json(['ok' => true]);
    }
}
