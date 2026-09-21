<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Document Archive: a central store for business documents (contracts,
     * invoices, certificates, licences...) with nested folders, tagging, expiry
     * tracking and file versioning.
     *
     * Files live under public/images/documents (the convention expenses,
     * purchases and meetings already use); `file_path` holds the path relative
     * to public/, e.g. "images/documents/1753_ab12_lease.pdf".
     *
     * No DB-level foreign keys, matching the promotions/contracts convention in
     * this codebase — integrity is enforced at the app layer.
     */
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->string('name', 191);
            // Self-referencing tree; null = a root folder.
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->text('description')->nullable();
            // Accent colour shown on the folder chip in the sidebar.
            $table->string('color', 20)->nullable();
            $table->integer('created_by')->nullable()->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('folder_id')->nullable()->index();
            $table->string('title', 191)->index();
            $table->text('description')->nullable();

            // Original upload name vs. the stored path (prefixed for uniqueness,
            // so it is never the name shown to users).
            $table->string('file_name', 191);
            $table->string('file_path', 255);
            $table->string('mime_type', 128)->nullable();
            $table->string('extension', 16)->nullable()->index();
            $table->unsignedBigInteger('size')->default(0);

            // JSON array of free-text tags, searched with a LIKE like the rest
            // of the admin's search boxes.
            $table->text('tags')->nullable();
            // Business reference the document relates to (invoice no, ref...).
            $table->string('reference', 100)->nullable()->index();

            $table->date('expiry_date')->nullable()->index();
            $table->boolean('is_starred')->default(false)->index();

            // Bumped by every replace; the current file always mirrors the
            // newest row in document_versions.
            $table->unsignedInteger('version')->default(1);

            $table->integer('uploaded_by')->nullable()->index();
            $table->timestamps(6);
            $table->softDeletes();
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->bigIncrements('id');
            $table->unsignedBigInteger('document_id')->index();
            $table->unsignedInteger('version');
            $table->string('file_name', 191);
            $table->string('file_path', 255);
            $table->string('mime_type', 128)->nullable();
            $table->string('extension', 16)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('note', 255)->nullable();
            $table->integer('uploaded_by')->nullable();
            $table->timestamps(6);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_folders');
    }
};
