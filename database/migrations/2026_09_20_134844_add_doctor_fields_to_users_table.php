<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Only meaningful for doctors. On duty is an explicit switch a doctor (or
     * an admin) flips, never inferred from being logged in, because a doctor
     * can be signed in and away from their desk.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->after('department_id')->constrained()->nullOnDelete();
            $table->boolean('is_on_duty')->default(false)->after('service_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn('is_on_duty');
        });
    }
};
