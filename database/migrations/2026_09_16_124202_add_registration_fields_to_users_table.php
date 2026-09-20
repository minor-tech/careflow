<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('facility_id')->nullable()->constrained()->cascadeOnDelete(); // null for platform-level admins
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('role', ['admin', 'receptionist', 'doctor', 'nurse']); // lab/pharmacy/manager: later sprint
            $table->string('title')->nullable(); // e.g. "Facility Manager"
            $table->string('phone');
            $table->boolean('two_factor_enabled')->default(true);
            $table->enum('status', ['active', 'suspended'])->default('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_id');
            $table->dropConstrainedForeignId('department_id');
            $table->dropColumn(['role', 'title', 'phone', 'two_factor_enabled', 'status']);
        });
    }
};
