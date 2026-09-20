<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Deliberately minimal (data minimisation): no national ID, address or
     * next-of-kin until something actually needs them.
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained();
            $table->string('name');
            $table->string('phone'); // stored normalised, e.g. +254712345678
            $table->date('dob')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->timestamps();

            // One patient record per phone per facility; also stops two
            // simultaneous registrations of a new number creating duplicates.
            $table->unique(['facility_id', 'phone']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
