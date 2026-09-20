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
     * Each department now keeps its own daily sequence. The row with no
     * department is the facility-wide sequence (the number given at
     * registration), which is why department_id is nullable and why every
     * existing counter, all of them facility-wide, is already correct.
     *
     * A unique key that includes a NULL doesn't stop duplicates (NULLs count
     * as different), so the key uses department_scope, which is the department
     * or 0 for the facility-wide row. That keeps "one counter per facility,
     * department and day" true for the facility-wide counter too.
     */
    public function up(): void
    {
        Schema::table('queue_counters', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('facility_id')->constrained();
            $table->unsignedBigInteger('department_scope')->virtualAs('coalesce(department_id, 0)');

            // The new key goes in first: the facility foreign key needs an
            // index that starts with facility_id at all times.
            $table->unique(['facility_id', 'department_scope', 'date'], 'queue_counters_facility_department_date_unique');
        });

        Schema::table('queue_counters', function (Blueprint $table) {
            $table->dropUnique('queue_counters_facility_id_date_unique');
        });
    }

    /**
     * Reverse the migrations. Per-department counters can't exist under the
     * old one-counter-per-day key, so they are removed first.
     */
    public function down(): void
    {
        DB::table('queue_counters')->whereNotNull('department_id')->delete();

        Schema::table('queue_counters', function (Blueprint $table) {
            $table->unique(['facility_id', 'date'], 'queue_counters_facility_id_date_unique');
        });

        Schema::table('queue_counters', function (Blueprint $table) {
            // department_scope is computed from department_id, so it has to go first.
            $table->dropUnique('queue_counters_facility_department_date_unique');
            $table->dropColumn('department_scope');
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
