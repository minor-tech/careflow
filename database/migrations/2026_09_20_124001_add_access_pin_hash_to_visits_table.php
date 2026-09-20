<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A hash of the visit's 4-digit access PIN, for following the visit by
     * typing the queue code and PIN instead of scanning the QR code. Never the
     * PIN itself. Nullable: visits from before this existed have no PIN, so the
     * typed route is closed for them (their link still works).
     */
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->string('access_pin_hash')->nullable()->after('tracking_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->dropColumn('access_pin_hash');
        });
    }
};
