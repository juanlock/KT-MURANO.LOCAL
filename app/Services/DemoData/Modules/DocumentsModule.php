<?php

namespace App\Services\DemoData\Modules;

use App\Services\DemoData\DemoContext;
use App\Services\DemoData\DemoModule;
use Carbon\Carbon;

/**
 * Document Archive examples: a small folder tree and archived documents with
 * their first version row. The archive tables have no company_id (the
 * archive is install-wide), so detection is install-wide too.
 *
 * No real files are uploaded: every document points at the app's existing
 * placeholder image (public/images/no-image.png), so preview/download work.
 * The archive never deletes files from disk, so reset can't remove it.
 */
class DocumentsModule extends DemoModule
{
    private const PLACEHOLDER = 'images/no-image.png';

    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'Document Archive';
    }

    public function dataTables(): array
    {
        return ['documents', 'document_folders'];
    }

    public function requiredTables(): array
    {
        return ['document_folders', 'documents', 'document_versions'];
    }

    public function generate(DemoContext $ctx): array
    {
        $size = is_file(public_path(self::PLACEHOLDER)) ? (int) filesize(public_path(self::PLACEHOLDER)) : 0;

        $folders = [];
        foreach ([
            ['Contracts', null, '#1677ff', 'Customer and supplier contracts'],
            ['HR', null, '#52c41a', 'Employee records and policies'],
            ['Invoices', null, '#fa8c16', 'Scanned supplier invoices'],
            ['Supplier Contracts', 'Contracts', '#722ed1', 'Agreements with suppliers'],
        ] as [$name, $parent, $color, $desc]) {
            $folders[$name] = $ctx->insert('document_folders', [
                'name' => $name,
                'parent_id' => $parent ? $folders[$parent] : null,
                'description' => $desc,
                'color' => $color,
                'created_by' => $ctx->userId,
            ]);
        }

        $docs = [
            ['Employee Handbook', 'HR', ['policy', 'hr'], null, null, true],
            ['Office Lease Agreement', 'Contracts', ['lease', 'legal'], 'LEASE-2026', 365, true],
            ['Supplier Agreement - Acme', 'Supplier Contracts', ['supplier', 'legal'], 'SUP-0042', 180, false],
            ['Invoice INV-1043 (scan)', 'Invoices', ['invoice', 'scan'], 'INV-1043', null, false],
            ['Business License', null, ['license', 'compliance'], 'BL-7781', 20, false],
        ];
        foreach ($docs as [$title, $folder, $tags, $ref, $expiresIn, $starred]) {
            $fileName = $title.'.png';
            $docId = $ctx->insert('documents', [
                'folder_id' => $folder ? $folders[$folder] : null,
                'title' => $title,
                'description' => 'Example document (placeholder file).',
                'file_name' => $fileName,
                'file_path' => self::PLACEHOLDER,
                'mime_type' => 'image/png',
                'extension' => 'png',
                'size' => $size,
                'tags' => json_encode($tags),
                'reference' => $ref,
                'expiry_date' => $expiresIn !== null ? Carbon::now()->addDays($expiresIn)->toDateString() : null,
                'is_starred' => $starred ? 1 : 0,
                'version' => 1,
                'uploaded_by' => $ctx->userId,
            ]);
            $ctx->insert('document_versions', [
                'document_id' => $docId,
                'version' => 1,
                'file_name' => $fileName,
                'file_path' => self::PLACEHOLDER,
                'mime_type' => 'image/png',
                'extension' => 'png',
                'size' => $size,
                'note' => 'Initial upload',
                'uploaded_by' => $ctx->userId,
            ]);
        }

        return [
            'Folders' => count($folders),
            'Documents' => count($docs),
            'Document versions' => count($docs),
        ];
    }
}
