<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A patient's verdict on one visit. The unique visit_id is the real guard
     * against a second answer for the same visit (a double tap, two open
     * tabs): the database refuses it whatever the application does.
     */
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('facility_id')->constrained();
            $table->unsignedTinyInteger('rating'); // 1 to 5 stars
            $table->json('issues')->nullable(); // what went wrong: only ever set for 3 stars or fewer
            $table->text('comment')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['facility_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
