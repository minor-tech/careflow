<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Structured context an event name can't carry: which doctor a patient was
     * assigned to, or who they were moved from and why.
     */
    public function up(): void
    {
        Schema::table('visit_events', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('visit_events', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
