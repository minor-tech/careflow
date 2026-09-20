<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Everything is off until an admin turns it on, so no facility starts
     * taking requests from strangers' phones by surprise. Times of day are the
     * clinic's own (Nairobi), like every other clock in the queue.
     */
    public function up(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->boolean('remote_queue_enabled')->default(false);
            $table->unsignedSmallInteger('remote_queue_max_pending')->default(20);
            $table->time('remote_queue_accept_until')->nullable();
            $table->unsignedTinyInteger('remote_queue_grace_minutes')->default(10);
            $table->boolean('remote_queue_allow_doctor_choice')->default(false);
            $table->boolean('remote_queue_allow_service_choice')->default(true);
            $table->boolean('self_checkin_enabled')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('facilities', function (Blueprint $table) {
            $table->dropColumn([
                'remote_queue_enabled',
                'remote_queue_max_pending',
                'remote_queue_accept_until',
                'remote_queue_grace_minutes',
                'remote_queue_allow_doctor_choice',
                'remote_queue_allow_service_choice',
                'self_checkin_enabled',
            ]);
        });
    }
};
