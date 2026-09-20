<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Off unless an admin turns it on, so no department changes how its queue
     * works until someone chooses to: with it on, each patient is assigned to
     * one doctor and waits in that doctor's own line instead of a shared one.
     */
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->boolean('requires_doctor_assignment')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropColumn('requires_doctor_assignment');
        });
    }
};
