<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Patient scans (2026-10-26): at the arrival (clinic check-in, hospital admission
 * window) the desk scans / photographs the insurance card (front, back) and the ID
 * document (front, back). Never a payment card -- of those only the type and last 4
 * digits are kept. Files are private: files/company_{id}/patient-scans/.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hms_patient_scans')) {
            return;
        }
        Schema::create('hms_patient_scans', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('company_id')->index();
            $t->unsignedBigInteger('patient_id')->index();
            $t->string('kind', 20); // insurance_front, insurance_back, id_front, id_back
            $t->string('path', 255);
            $t->string('original_name', 191)->nullable();
            $t->string('mime', 60)->nullable();
            $t->unsignedInteger('size')->nullable();
            $t->unsignedBigInteger('appointment_id')->nullable();
            $t->unsignedBigInteger('er_case_id')->nullable();
            $t->unsignedBigInteger('admission_id')->nullable();
            $t->integer('uploaded_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hms_patient_scans');
    }
};
