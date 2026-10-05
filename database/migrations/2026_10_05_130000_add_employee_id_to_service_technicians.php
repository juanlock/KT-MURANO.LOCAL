<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Employee form -> "Service Technician": a technician can be one of the
 * company's employees (service_technicians.employee_id). Technicians typed by
 * hand on Service -> Technicians keep employee_id null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('service_technicians', 'employee_id')) {
            Schema::table('service_technicians', function (Blueprint $t) {
                $t->unsignedInteger('employee_id')->nullable()->index()->after('company_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_technicians', 'employee_id')) {
            Schema::table('service_technicians', function (Blueprint $t) {
                $t->dropIndex(['employee_id']);
                $t->dropColumn('employee_id');
            });
        }
    }
};
