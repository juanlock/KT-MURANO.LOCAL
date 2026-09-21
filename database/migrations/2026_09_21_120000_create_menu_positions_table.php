<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ported from KT-Billing: backs the "Manage Menus" admin sidebar editor
     * (item_key/parent_key tree, up to 4 levels deep, custom items via
     * is_custom+template+dynamic_table). Not company-scoped in Billing either
     * -- one shared menu layout for the whole install.
     */
    public function up(): void
    {
        Schema::create('menu_positions', function (Blueprint $table) {
            $table->id();
            $table->string('item_key')->unique();
            $table->string('parent_key')->nullable();
            $table->string('label')->nullable();
            $table->string('icon')->nullable();
            $table->string('route')->nullable();
            $table->boolean('external')->default(0);
            $table->boolean('is_custom')->default(0);
            $table->string('template')->nullable();
            $table->string('dynamic_table')->nullable();
            $table->boolean('hidden')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_positions');
    }
};
