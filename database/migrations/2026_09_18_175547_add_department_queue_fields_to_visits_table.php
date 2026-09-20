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
     * queue_number stays the patient's one reference number for the whole
     * visit. These two describe the current leg: the local number the
     * department gave them (null until they are transferred into one) and when
     * they arrived there.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->unsignedInteger('department_queue_number')->nullable()->after('queue_number');
            $table->timestamp('department_entered_at')->nullable()->after('department_queue_number');
        });

        // Every visit so far is still on its first leg, entered at registration.
        DB::table('visits')->whereNull('department_entered_at')->update([
            'department_entered_at' => DB::raw('created_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['department_queue_number', 'department_entered_at']);
        });
    }
};
