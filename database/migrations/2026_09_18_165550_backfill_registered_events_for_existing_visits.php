<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Visits registered before the audit log existed have no first entry. Give
     * each one a "registered" event built from what the visit itself recorded
     * (when it was created, by whom, in which department) so every visit's
     * journey starts at the beginning. Visits that already have one are left
     * alone, so this is safe to run more than once.
     */
    public function up(): void
    {
        $withoutRegisteredEvent = DB::table('visits')
            ->select('id', 'department_id', DB::raw("'registered'"), 'created_by', 'created_at')
            ->whereNotExists(function (Builder $query) {
                $query->select(DB::raw(1))
                    ->from('visit_events')
                    ->whereColumn('visit_events.visit_id', 'visits.id')
                    ->where('visit_events.event', 'registered');
            })
            ->orderBy('id');

        DB::table('visit_events')->insertUsing(
            ['visit_id', 'department_id', 'event', 'user_id', 'created_at'],
            $withoutRegisteredEvent,
        );
    }

    /**
     * Reverse the migrations. Deliberately does nothing: the audit log is
     * append-only, and the rows added here can't be told apart from real ones.
     */
    public function down(): void
    {
        //
    }
};
