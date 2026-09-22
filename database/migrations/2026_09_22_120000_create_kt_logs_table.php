<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * kt_logs -- a running journal of Claude Code work sessions on this repo
 * (not app-facing, not used by any controller/UI). One row per distinct
 * request within a conversation: what was asked, how it was understood,
 * the relevant chat text, what got changed, and where the request came
 * from. Appended to at the end of a work session per the owner's request.
 *
 * Ported from KT-BILLING.LOCAL (dbbilling) as part of the kt_log module
 * migration -- see CLAUDE.md protocol "preparate". Combines billing's two
 * migrations (create table + add changes_detail) into one since this is a
 * fresh table in dbmurano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kt_logs', function (Blueprint $table) {
            $table->id();
            $table->date('log_date');
            $table->string('claude_user');
            // Which device/location the request came from -- e.g.
            // 'ThinkBook1', 'Home'. Free text, not an enum, since new
            // devices/locations will show up over time.
            $table->string('location');
            $table->longText('request_user');
            $table->longText('claude_understand');
            $table->longText('chat_transcript');
            $table->longText('files_modified')->nullable();
            $table->longText('actions')->nullable();
            // Granular, itemized account of the actual changes made --
            // distinct from `actions` (high-level steps) and
            // `chat_transcript` (narrative).
            $table->longText('changes_detail')->nullable();
            // Extra columns beyond what was asked, kept short/scannable so
            // the table is browsable without opening every long-text field.
            $table->string('summary')->nullable();
            $table->string('category')->nullable();
            $table->string('company_context')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kt_logs');
    }
};
