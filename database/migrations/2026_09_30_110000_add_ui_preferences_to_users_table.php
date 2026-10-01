<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Per-user UI preferences (JSON object, key => value) saved on the server so
// they follow the user to every PC/browser and survive logout / cleared
// browser data -- starting with each list's show/hide columns choice
// (DataTable gear, key "dt_hidden_columns_<page>"). Served in get_user_auth
// as user.ui_preferences; written via PUT user_preferences.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'ui_preferences')) {
                $table->json('ui_preferences')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'ui_preferences')) {
                $table->dropColumn('ui_preferences');
            }
        });
    }
};
