<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A record of every message sent to a patient. Named patient_notifications
     * rather than "notifications" because that name is Laravel's own for its
     * database notification channel. facility_id is kept here (a patient's
     * facility never changes) so the admin's list is scoped by a column of its
     * own rather than by joining through patients.
     */
    public function up(): void
    {
        Schema::create('patient_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained();
            $table->foreignId('patient_id')->constrained();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('channel', ['sms', 'whatsapp', 'email']); // only sms is used so far
            $table->text('message');
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->text('provider_response')->nullable(); // the gateway's raw answer or the error, for debugging delivery
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'created_at']);
            $table->index(['facility_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('patient_notifications');
    }
};
