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
     * An account whose password someone else chose (and passed on, perhaps read
     * out loud) must be given a password of its own before anything else. The
     * default is false so accounts made any other way, such as a facility
     * admin who picked their own password at registration, or the system
     * admin, are never locked behind the screen; staff accounts created by an
     * admin set it explicitly.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });

        // Staff that already exist were all created by an admin with a
        // temporary password, and we can't tell which have changed it since,
        // so every one is asked again (one extra step at worst).
        DB::table('users')
            ->whereIn('role', ['receptionist', 'doctor', 'nurse'])
            ->update(['must_change_password' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
