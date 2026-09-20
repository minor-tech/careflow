<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Append-only: one row for every step of a visit's journey, so the whole
     * journey can be rebuilt later. Rows are never updated, and the foreign
     * keys restrict rather than null out, so deleting a staff member or a
     * department can't quietly erase who did what, or where.
     */
    public function up(): void
    {
        Schema::create('visit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained();
            $table->foreignId('department_id')->nullable()->constrained(); // the visit's department at the time
            $table->string('event'); // registered, called, started, recalled, completed, sent_onward, cancelled
            $table->foreignId('user_id')->nullable()->constrained(); // staff member who triggered it
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_events');
    }
};
