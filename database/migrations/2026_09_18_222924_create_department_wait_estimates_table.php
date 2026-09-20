<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * What each department has been measured to take per patient, learned
     * from the visit log by a nightly job (RecalculateDepartmentAverages) and
     * read by WaitEstimator. One row per department; a department that hasn't
     * been measured has no row and the configured default is used instead.
     */
    public function up(): void
    {
        Schema::create('department_wait_estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('avg_minutes', 6, 1); // minutes a patient spends being served here
            $table->unsignedInteger('sample_size'); // how many patients that average is from
            $table->timestamp('calculated_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('department_wait_estimates');
    }
};
