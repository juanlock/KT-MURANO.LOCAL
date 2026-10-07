<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website Management -> Front page -> Saved websites (2026-10-06): before an
 * import replaces the imported website, a copy (snapshot) of the current one is
 * kept in files/company_{id}/website/snapshots/{stamp}/ and listed as a theme
 * the company can switch back to (App\Services\WebsiteSnapshots).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('website_snapshots')) {
            return;
        }
        Schema::create('website_snapshots', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id')->index();
            $t->string('name', 190);
            $t->string('source_url', 255)->nullable();
            $t->string('path', 255);  // the copy's folder, relative to public/
            $t->string('entry', 255); // its front page file, relative to public/
            $t->timestamp('imported_at')->nullable(); // when the copied site was imported
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_snapshots');
    }
};
