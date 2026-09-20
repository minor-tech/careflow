<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'receptionist', 'doctor', 'nurse', 'system_admin'])->change();

            // A system admin has no facility and need not have a phone.
            $table->string('phone')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations. System admins cannot exist in the narrower
     * schema, so they are removed first rather than left to break the change.
     */
    public function down(): void
    {
        DB::table('users')->where('role', 'system_admin')->delete();
        DB::table('users')->whereNull('phone')->update(['phone' => '']);

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'receptionist', 'doctor', 'nurse'])->change();
            $table->string('phone')->nullable(false)->change();
        });
    }
};
