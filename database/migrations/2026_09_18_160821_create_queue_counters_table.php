<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Exists purely to make queue-number generation atomic; the number is
     * never derived by counting visits.
     */
    public function up(): void
    {
        Schema::create('queue_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('last_number')->default(0);

            $table->unique(['facility_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('queue_counters');
    }
};
