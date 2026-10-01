<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1. employee_positions: one employee can belong to several departments /
 *    designations (one person = one employee = one login, e.g. a stylist who
 *    is both "Nail Artist" and "Acrylics expert"). The primary position is
 *    still mirrored on employees.department_id / designation_id, which the
 *    rest of HRM reads. Every existing employee gets its current position.
 * 2. settings.default_password_*: System Settings -> Defaults -> Users --
 *    the password (stored hashed) given to users the system creates on its
 *    own for employees, customers, web-store customers, suppliers and sales
 *    agents. Empty = a random password (no login until one is set).
 */
return new class extends Migration
{
    public const PASSWORD_TYPES = ['employee', 'customer', 'online_store', 'supplier', 'sales_agent'];

    public function up(): void
    {
        if (! Schema::hasTable('employee_positions')) {
            Schema::create('employee_positions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->index();
                $table->unsignedBigInteger('department_id');
                $table->unsignedBigInteger('designation_id');
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->unique(['employee_id', 'designation_id']);
            });
            $now = now();
            DB::table('employees')->whereNotNull('designation_id')->orderBy('id')->chunk(500, function ($rows) use ($now) {
                DB::table('employee_positions')->insert($rows->map(fn ($e) => [
                    'employee_id' => $e->id, 'department_id' => $e->department_id, 'designation_id' => $e->designation_id,
                    'is_primary' => 1, 'created_at' => $now, 'updated_at' => $now,
                ])->all());
            });
        }

        Schema::table('settings', function (Blueprint $table) {
            foreach (self::PASSWORD_TYPES as $t) {
                if (! Schema::hasColumn('settings', "default_password_{$t}")) {
                    $table->string("default_password_{$t}")->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_positions');
        Schema::table('settings', function (Blueprint $table) {
            foreach (self::PASSWORD_TYPES as $t) {
                if (Schema::hasColumn('settings', "default_password_{$t}")) {
                    $table->dropColumn("default_password_{$t}");
                }
            }
        });
    }
};
