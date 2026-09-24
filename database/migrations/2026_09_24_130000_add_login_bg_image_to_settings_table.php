<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Login Page Settings: background can now be a solid color (login_bg_color,
 * existing) OR an uploaded image -- mutually exclusive, image wins when both
 * are present (see login.blade.php). Stored the same way as the login logo:
 * a full relative path under the fixed global public/images/loginpage/
 * folder (never per-company -- this is the one shared login screen).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'login_bg_image')) {
            return;
        }
        Schema::table('settings', function (Blueprint $table) {
            $table->string('login_bg_image')->nullable()->after('login_bg_color');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'login_bg_image')) {
            Schema::table('settings', function (Blueprint $table) {
                $table->dropColumn('login_bg_image');
            });
        }
    }
};
