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
     * The status column was a database enum, which can't take a new value
     * without being altered again each time; it becomes a plain string (the
     * model casts it to VisitStatus), which loses nothing. A remote-accepted
     * visit sits in awaiting_arrival, holding its place in the line, until staff
     * check the patient in.
     *
     * arrival_grace_started_at is when staff were first shown that this
     * not-yet-arrived patient is next in line, so the grace period before a
     * no-show is decided has a start.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('status', 32)->default('waiting')->change();
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->string('source', 20)->default('walk_in')->after('status'); // walk_in, remote, appointment, self_checkin
            $table->timestamp('arrived_at')->nullable()->after('source');
            $table->timestamp('arrival_signaled_at')->nullable()->after('arrived_at');
            $table->timestamp('arrival_grace_started_at')->nullable()->after('arrival_signaled_at');
        });
    }

    /**
     * Reverse the migrations. A visit that is still awaiting arrival can't
     * exist under the old enum, so it goes back to waiting.
     */
    public function down(): void
    {
        DB::table('visits')->where('status', 'awaiting_arrival')->update(['status' => 'waiting']);

        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn(['source', 'arrived_at', 'arrival_signaled_at', 'arrival_grace_started_at']);
        });

        Schema::table('visits', function (Blueprint $table) {
            $table->enum('status', ['waiting', 'called', 'in_service', 'waiting_department', 'completed', 'cancelled'])->default('waiting')->change();
        });
    }
};
