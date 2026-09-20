<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A doctor's own daily sequence sits beside the facility-wide and
     * per-department ones: doctor_id is null for those, so every existing
     * counter stays correct. As with department_id, a unique key containing a
     * NULL wouldn't stop duplicates, so the key uses doctor_scope (the doctor,
     * or 0), which keeps "one counter per facility, department, doctor and day"
     * true for the counters that have no doctor.
     */
    public function up(): void
    {
        Schema::table('queue_counters', function (Blueprint $table) {
            $table->foreignId('doctor_id')->nullable()->after('department_id')->constrained('users');
            $table->unsignedBigInteger('doctor_scope')->virtualAs('coalesce(doctor_id, 0)');

            // The new key goes in first: the facility foreign key needs an
            // index that starts with facility_id at all times.
            $table->unique(['facility_id', 'department_scope', 'doctor_scope', 'date'], 'queue_counters_facility_department_doctor_date_unique');
        });

        Schema::table('queue_counters', function (Blueprint $table) {
            $table->dropUnique('queue_counters_facility_department_date_unique');
        });
    }

    /**
     * Reverse the migrations. Personal counters can't exist under the old
     * key, so they are removed first.
     */
    public function down(): void
    {
        DB::table('queue_counters')->whereNotNull('doctor_id')->delete();

        Schema::table('queue_counters', function (Blueprint $table) {
            $table->unique(['facility_id', 'department_scope', 'date'], 'queue_counters_facility_department_date_unique');
        });

        Schema::table('queue_counters', function (Blueprint $table) {
            // doctor_scope is computed from doctor_id, so it has to go first.
            $table->dropUnique('queue_counters_facility_department_doctor_date_unique');
            $table->dropColumn('doctor_scope');
            $table->dropConstrainedForeignId('doctor_id');
        });
    }
};
