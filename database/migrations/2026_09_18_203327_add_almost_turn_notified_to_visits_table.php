<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Whether the patient has been told they are almost up in the queue they
     * are in now, so the message goes out once, not on every check. It is
     * cleared when they are sent to another department, which is a new queue.
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->boolean('almost_turn_notified')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('almost_turn_notified');
        });
    }
};
