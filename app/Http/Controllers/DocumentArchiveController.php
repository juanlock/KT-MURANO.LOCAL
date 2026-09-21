<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentFolder;
use App\Models\DocumentVersion;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Document Archive — central store for business documents.
 *
 * Files live under public/images/documents, the same place expenses, purchases
 * and meetings put their uploads (and one of the paths the auto-updater
 * preserves across releases). They are therefore served directly by the web
 * server: `url` on each row points straight at the file, and download() exists
 * only to force the original filename as an attachment.
 *
 * Because that directory is web-reachable, uploads with an executable
 * extension are rejected — see EXECUTABLE_EXTENSIONS.
 *
 * The list endpoint follows the admin's usual contract
 * (page/SortField/SortType/search/limit -> { documents, totalRows }) so the
 * Vue `useCrudTable` composable drives it unchanged.
 */
class DocumentArchiveController extends Controller
{
    /** Upload directory, relative to public/. */
    private const UPLOAD_DIR = 'images/documents';

    /**
     * Never accept these: the archive directory is served by the web server, so
     * a stored .php would be a remote-code-execution hole rather than a file.
     */
    private const EXECUTABLE_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phps', 'phar',
        'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'jsp', 'asp', 'aspx', 'htaccess',
    ];

    /** Sortable columns, whitelisted — anything computed would 1054 in MySQL. */
    private const SORTABLE = ['id', 'title', 'file_name', 'size', 'extension', 'expiry_date', 'created_at', 'updated_at'];

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Document::class);

        $perPage = (int) ($request->limit ?? 12);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Document::with('folder')->whereNull('deleted_at');

        $this->applyFilters($query, $request);

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $documents = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        $uploaders = $this->uploaderNames($documents->pluck('uploaded_by')->all());

        return response()->json([
            'totalRows' => $totalRows,
            'documents' => $documents->map(fn ($doc) => $this->present($doc, $uploaders))->values(),
        ]);
    }

    /**
     * Shared by index() and the counters, so a folder/type/expiry filter always
     * means the same thing in the list and in the badge next to it.
     */
    private function applyFilters($query, Request $request)
    {
        // Folder: browsing a parent includes everything filed below it.
        // 'none' is the pseudo-folder holding everything not filed anywhere.
        if ($request->get('folder_id') === 'none') {
            $query->whereNull('folder_id');
        } elseif ($request->filled('folder_id')) {
            $query->whereIn('folder_id', DocumentFolder::descendantIds($request->folder_id));
        }

        if ($request->filled('kind')) {
            if ($request->kind === 'other') {
                // "other" is the complement of every known family.
                $known = $this->allKnownExtensions();
                $query->where(function ($q) use ($known) {
                    $q->whereNull('extension')
                        ->orWhere('extension', '')
                        ->orWhereNotIn(DB::raw('LOWER(extension)'), $known);
                });
            } else {
                $extensions = $this->extensionsForKind($request->kind);
                // An unknown kind must not silently return everything.
                $query->whereIn(DB::raw('LOWER(extension)'), $extensions ?: ['']);
            }
        }

        if ($request->boolean('starred')) {
            $query->where('is_starred', 1);
        }

        // Expiring: due within N days (default 30), including already expired.
        if ($request->filled('expiring')) {
            $days = (int) $request->expiring ?: 30;
            $query->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', Carbon::today()->addDays($days)->toDateString());
        }

        if ($request->filled('tag')) {
            $query->where('tags', 'LIKE', '%"' . $request->tag . '"%');
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'LIKE', "%{$search}%")
                    ->orWhere('description', 'LIKE', "%{$search}%")
                    ->orWhere('file_name', 'LIKE', "%{$search}%")
                    ->orWhere('reference', 'LIKE', "%{$search}%")
                    ->orWhere('tags', 'LIKE', "%{$search}%");
            });
        }

        return $query;
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Document::class);

        $document = Document::with('folder', 'versions')->whereNull('deleted_at')->findOrFail($id);
        $uploaders = $this->uploaderNames(
            array_merge([$document->uploaded_by], $document->versions->pluck('uploaded_by')->all())
        );

        $data = $this->present($document, $uploaders);
        $data['versions'] = $document->versions->map(fn ($v) => [
            'id' => $v->id,
            'version' => $v->version,
            'file_name' => $v->file_name,
            'url' => $v->file_path ? asset($v->file_path) : null,
            'size' => (int) $v->size,
            'size_label' => $this->humanSize($v->size),
            'note' => $v->note,
            'uploaded_by_name' => $uploaders[$v->uploaded_by] ?? '',
            'created_at' => optional($v->created_at)->toIso8601String(),
        ])->values();

        return response()->json(['document' => $data]);
    }

    /**
     * Upload one or many files. Each file becomes its own document; the title
     * falls back to the file name so a bulk drop needs no typing at all.
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Document::class);

        $request->validate([
            'files' => 'required|array|min:1',
            'files.*' => 'required|file|max:51200',
            'title' => 'nullable|string|max:191',
            'folder_id' => 'nullable|exists:document_folders,id',
            'expiry_date' => 'nullable|date',
            'reference' => 'nullable|string|max:100',
        ]);

        $userId = optional($request->user('api'))->id;
        $tags = $this->normaliseTags($request->input('tags'));
        $files = $request->file('files');
        $single = count($files) === 1;
        $created = [];

        foreach ($files as $file) {
            $stored = $this->storeFile($file);

            $document = Document::create([
                'folder_id' => $request->folder_id ?: null,
                // A batch upload keeps each file's own name; a single upload may
                // be renamed by the form.
                'title' => $single && $request->filled('title')
                    ? $request->title
                    : pathinfo($stored['file_name'], PATHINFO_FILENAME),
                'description' => $request->description,
                'file_name' => $stored['file_name'],
                'file_path' => $stored['file_path'],
                'mime_type' => $stored['mime_type'],
                'extension' => $stored['extension'],
                'size' => $stored['size'],
                'tags' => $tags ? json_encode($tags) : null,
                'reference' => $request->reference,
                'expiry_date' => $request->expiry_date ?: null,
                'is_starred' => $request->boolean('is_starred'),
                'version' => 1,
                'uploaded_by' => $userId,
            ]);

            DocumentVersion::create(array_merge($stored, [
                'document_id' => $document->id,
                'version' => 1,
                'note' => 'Initial upload',
                'uploaded_by' => $userId,
            ]));

            $created[] = $document->id;
        }

        return response()->json(['success' => true, 'ids' => $created, 'count' => count($created)]);
    }

    /** Metadata only — the file itself is replaced through storeVersion(). */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Document::class);

        $request->validate([
            'title' => 'required|string|max:191',
            'folder_id' => 'nullable|exists:document_folders,id',
            'expiry_date' => 'nullable|date',
            'reference' => 'nullable|string|max:100',
        ]);

        $document = Document::whereNull('deleted_at')->findOrFail($id);
        $tags = $this->normaliseTags($request->input('tags'));

        $document->update([
            'title' => $request->title,
            'description' => $request->description,
            'folder_id' => $request->folder_id ?: null,
            'tags' => $tags ? json_encode($tags) : null,
            'reference' => $request->reference,
            'expiry_date' => $request->expiry_date ?: null,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Soft delete. The files stay on disk on purpose: an archive that loses the
     * original the moment somebody mis-clicks is not an archive.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Document::class);

        Document::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Document::class);

        $ids = (array) $request->selectedIds;
        Document::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /** Bulk file-away: move a selection into a folder (null = uncategorised). */
    public function moveBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', Document::class);

        $request->validate([
            'selectedIds' => 'required|array|min:1',
            'folder_id' => 'nullable|exists:document_folders,id',
        ]);

        Document::whereIn('id', $request->selectedIds)
            ->update(['folder_id' => $request->folder_id ?: null]);

        return response()->json(['success' => true]);
    }

    public function toggleStar(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Document::class);

        $document = Document::whereNull('deleted_at')->findOrFail($id);
        $document->is_starred = ! $document->is_starred;
        $document->save();

        return response()->json(['success' => true, 'is_starred' => (bool) $document->is_starred]);
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

    /**
     * The file is web-reachable at its `url`, so this exists purely to send it
     * as an attachment under the name it was uploaded with (the stored name
     * carries a uniqueness prefix nobody wants to see in their downloads).
     */
    public function download(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Document::class);

        $document = Document::whereNull('deleted_at')->findOrFail($id);
        $path = public_path($document->file_path);

        if (! file_exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->download($path, $document->file_name);
    }

    // ------------------------------------------------------------------
    // Versions
    // ------------------------------------------------------------------

    /** Replace the file, keeping the old one retrievable as version N-1. */
    public function storeVersion(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Document::class);

        $request->validate([
            'file' => 'required|file|max:51200',
            'note' => 'nullable|string|max:255',
        ]);

        $document = Document::whereNull('deleted_at')->findOrFail($id);
        $stored = $this->storeFile($request->file('file'));
        $version = (int) $document->version + 1;

        DocumentVersion::create(array_merge($stored, [
            'document_id' => $document->id,
            'version' => $version,
            'note' => $request->note,
            'uploaded_by' => optional($request->user('api'))->id,
        ]));

        $document->update(array_merge($stored, ['version' => $version]));

        return response()->json(['success' => true, 'version' => $version]);
    }

    public function downloadVersion(Request $request, $documentId, $versionId)
    {
        $this->authorizeForUser($request->user('api'), 'view', Document::class);

        $version = DocumentVersion::where('document_id', $documentId)->findOrFail($versionId);
        $path = public_path($version->file_path);

        if (! file_exists($path)) {
            return response()->json(['message' => 'File not found'], 404);
        }

        return response()->download($path, $version->file_name);
    }

    /**
     * Roll back to an older file. History is append-only — restoring records a
     * NEW version pointing at the old file rather than rewriting the past.
     */
    public function restoreVersion(Request $request, $documentId, $versionId)
    {
        $this->authorizeForUser($request->user('api'), 'update', Document::class);

        $document = Document::whereNull('deleted_at')->findOrFail($documentId);
        $old = DocumentVersion::where('document_id', $documentId)->findOrFail($versionId);
        $version = (int) $document->version + 1;

        DocumentVersion::create([
            'document_id' => $document->id,
            'version' => $version,
            'file_name' => $old->file_name,
            'file_path' => $old->file_path,
            'mime_type' => $old->mime_type,
            'extension' => $old->extension,
            'size' => $old->size,
            'note' => 'Restored from v' . $old->version,
            'uploaded_by' => optional($request->user('api'))->id,
        ]);

        $document->update([
            'file_name' => $old->file_name,
            'file_path' => $old->file_path,
            'mime_type' => $old->mime_type,
            'extension' => $old->extension,
            'size' => $old->size,
            'version' => $version,
        ]);

        return response()->json(['success' => true, 'version' => $version]);
    }

    // ------------------------------------------------------------------
    // Folders
    // ------------------------------------------------------------------

    /** Flat list + per-folder document counts; the client builds the tree. */
    public function folders(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Document::class);

        $folders = DocumentFolder::whereNull('deleted_at')->orderBy('name')->get();

        $counts = Document::whereNull('deleted_at')
            ->whereNotNull('folder_id')
            ->select('folder_id', DB::raw('count(*) as aggregate'))
            ->groupBy('folder_id')
            ->pluck('aggregate', 'folder_id')
            ->toArray();

        return response()->json([
            'folders' => $folders->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'parent_id' => $f->parent_id,
                'description' => $f->description,
                'color' => $f->color,
                'count' => (int) ($counts[$f->id] ?? 0),
            ])->values(),
            'uncategorised' => Document::whereNull('deleted_at')->whereNull('folder_id')->count(),
        ]);
    }

    public function storeFolder(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Document::class);

        $request->validate([
            'name' => 'required|string|max:191',
            'parent_id' => 'nullable|exists:document_folders,id',
        ]);

        DocumentFolder::create([
            'name' => $request->name,
            'parent_id' => $request->parent_id ?: null,
            'description' => $request->description,
            'color' => $request->color,
            'created_by' => optional($request->user('api'))->id,
        ]);

        return response()->json(['success' => true]);
    }

    public function updateFolder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Document::class);

        $request->validate([
            'name' => 'required|string|max:191',
            'parent_id' => 'nullable|exists:document_folders,id',
        ]);

        $folder = DocumentFolder::whereNull('deleted_at')->findOrFail($id);

        // A folder cannot be filed inside itself or one of its own descendants.
        $parentId = $request->parent_id ?: null;
        if ($parentId && in_array((int) $parentId, DocumentFolder::descendantIds($folder->id), true)) {
            return response()->json(['message' => 'A folder cannot be moved inside itself.'], 422);
        }

        $folder->update([
            'name' => $request->name,
            'parent_id' => $parentId,
            'description' => $request->description,
            'color' => $request->color,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a folder. Its documents and sub-folders are NOT deleted — they are
     * detached to the root so nothing silently disappears from the archive.
     */
    public function destroyFolder(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Document::class);

        $folder = DocumentFolder::whereNull('deleted_at')->findOrFail($id);

        Document::where('folder_id', $folder->id)->update(['folder_id' => null]);
        DocumentFolder::where('parent_id', $folder->id)->update(['parent_id' => null]);
        $folder->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Overview
    // ------------------------------------------------------------------

    /** Counters for the header tiles. */
    public function stats(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Document::class);

        $base = fn () => Document::whereNull('deleted_at');
        $today = Carbon::today();
        $storage = (int) $base()->sum('size');

        return response()->json([
            'total' => $base()->count(),
            'storage' => $storage,
            'storage_label' => $this->humanSize($storage),
            'starred' => $base()->where('is_starred', 1)->count(),
            'folders' => DocumentFolder::whereNull('deleted_at')->count(),
            'expiring_soon' => $base()->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '>=', $today->toDateString())
                ->whereDate('expiry_date', '<=', $today->copy()->addDays(30)->toDateString())
                ->count(),
            'expired' => $base()->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<', $today->toDateString())
                ->count(),
            'added_this_month' => $base()->whereDate('created_at', '>=', $today->copy()->startOfMonth()->toDateString())->count(),
            'tags' => $this->allTags(),
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Move an upload into public/images/documents and return the columns both
     * `documents` and `document_versions` store (their names line up, so the
     * result is spread straight into either create()).
     *
     * The stored name is prefixed like every other upload in this codebase
     * (time + random) so two files called "invoice.pdf" never collide; the
     * original name is kept separately for display and downloads.
     */
    private function storeFile($file)
    {
        $original = $file->getClientOriginalName();
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, self::EXECUTABLE_EXTENSIONS, true)) {
            abort(422, 'Files of type .' . $extension . ' cannot be archived.');
        }

        $uploadPath = public_path(self::UPLOAD_DIR);
        if (! file_exists($uploadPath)) {
            mkdir($uploadPath, 0755, true);
        }

        // Capture metadata BEFORE move() — the temp file is unreadable after.
        $size = $file->getSize();
        $mime = $file->getClientMimeType();

        // basename() strips any directory the client smuggled into the name.
        $safeName = Str::slug(pathinfo(basename($original), PATHINFO_FILENAME)) ?: 'file';
        $filename = time() . '_' . Str::random(10) . '_' . $safeName . ($extension ? '.' . $extension : '');

        $file->move($uploadPath, $filename);

        return [
            'file_name' => $original,
            'file_path' => self::UPLOAD_DIR . '/' . $filename,
            'mime_type' => $mime,
            'extension' => $extension,
            'size' => $size,
        ];
    }

    private function present(Document $doc, array $uploaders = [])
    {
        $expiry = $doc->expiry_date ? Carbon::parse($doc->expiry_date) : null;

        return [
            'id' => $doc->id,
            'title' => $doc->title,
            'description' => $doc->description,
            'folder_id' => $doc->folder_id,
            'folder_name' => $doc->folder ? $doc->folder->name : null,
            'file_name' => $doc->file_name,
            // Served straight from public/ — the preview pane points at this.
            'url' => $doc->file_path ? asset($doc->file_path) : null,
            'mime_type' => $doc->mime_type,
            'extension' => $doc->extension,
            'kind' => $doc->kind,
            'size' => (int) $doc->size,
            'size_label' => $this->humanSize($doc->size),
            'tags' => $doc->tag_list,
            'reference' => $doc->reference,
            'expiry_date' => $expiry ? $expiry->toDateString() : null,
            'days_to_expiry' => $expiry ? Carbon::today()->diffInDays($expiry, false) : null,
            'is_starred' => (bool) $doc->is_starred,
            'version' => (int) $doc->version,
            'uploaded_by' => $doc->uploaded_by,
            'uploaded_by_name' => $uploaders[$doc->uploaded_by] ?? '',
            'created_at' => optional($doc->created_at)->toIso8601String(),
            'updated_at' => optional($doc->updated_at)->toIso8601String(),
        ];
    }

    /** id => username, in one query instead of an N+1 per row. */
    private function uploaderNames(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));

        if (! $ids) {
            return [];
        }

        return DB::table('users')->whereIn('id', $ids)->pluck('username', 'id')->toArray();
    }

    /** Accepts a JSON array, a real array, or a comma-separated string. */
    private function normaliseTags($raw)
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : explode(',', $raw);
        }

        if (! is_array($raw)) {
            return [];
        }

        $tags = array_map(fn ($t) => trim((string) $t), $raw);

        return array_values(array_unique(array_filter($tags, 'strlen')));
    }

    private function allTags()
    {
        $tags = [];
        Document::whereNull('deleted_at')->whereNotNull('tags')
            ->pluck('tags')
            ->each(function ($raw) use (&$tags) {
                foreach ((array) json_decode((string) $raw, true) as $tag) {
                    $tag = trim((string) $tag);
                    if ($tag === '') {
                        continue;
                    }
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                }
            });

        arsort($tags);

        return collect($tags)->take(30)->map(fn ($count, $tag) => ['tag' => $tag, 'count' => $count])->values();
    }

    private function extensionsForKind($kind)
    {
        $map = [
            'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic'],
            'pdf' => ['pdf'],
            'word' => ['doc', 'docx', 'odt', 'rtf'],
            'excel' => ['xls', 'xlsx', 'ods', 'csv'],
            'powerpoint' => ['ppt', 'pptx', 'odp'],
            'archive' => ['zip', 'rar', '7z', 'tar', 'gz'],
            'video' => ['mp4', 'avi', 'mov', 'mkv', 'webm'],
            'audio' => ['mp3', 'wav', 'ogg', 'm4a'],
            'text' => ['txt', 'md', 'log', 'json', 'xml'],
        ];

        return $map[$kind] ?? [];
    }

    private function allKnownExtensions()
    {
        $all = [];
        foreach (['image', 'pdf', 'word', 'excel', 'powerpoint', 'archive', 'video', 'audio', 'text'] as $kind) {
            $all = array_merge($all, $this->extensionsForKind($kind));
        }

        return $all;
    }

    private function humanSize($bytes)
    {
        $bytes = (int) $bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}
