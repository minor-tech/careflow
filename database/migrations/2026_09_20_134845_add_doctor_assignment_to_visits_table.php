<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * assigned_doctor_id stays on the visit as history (the doctor's workload
     * is reported from it) even after the patient moves on. doctor_queue_number
     * is what says the patient is in that doctor's line right now: it is set
     * when they join it and cleared when they leave the department. Restrict
     * foreign keys, like the audit log's, so nobody's history can vanish.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('assigned_doctor_id')->nullable()->after('department_id')->constrained('users');
            $table->unsignedInteger('doctor_queue_number')->nullable()->after('department_queue_number');
            $table->foreignId('service_id')->nullable()->after('assigned_doctor_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_doctor_id');
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn('doctor_queue_number');
        });
    }
};
