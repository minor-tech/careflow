<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A request is not a visit: it may be declined, and for someone new to the
     * facility there is no patient record yet. Only accepting it makes both.
     * Status and source are plain strings (cast to enums in the model) so a new
     * value never needs the column altered.
     */
    public function up(): void
    {
        Schema::create('remote_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 20);
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('preferred_doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('remote'); // remote (from home) or self_checkin (already here)
            $table->time('requested_arrival');
            $table->string('status', 20)->default('pending');
            $table->string('public_code', 20)->unique(); // the secret in the requester's status link
            $table->string('declined_reason')->nullable();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('recommended_arrival_from')->nullable();
            $table->timestamp('recommended_arrival_until')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('remote_requests');
    }
};
